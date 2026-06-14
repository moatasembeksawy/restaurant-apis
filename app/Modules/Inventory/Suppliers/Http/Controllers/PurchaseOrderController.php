<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Controllers;

use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\Inventory\Suppliers\Http\Requests\IndexPurchaseOrderRequest;
use App\Modules\Inventory\Suppliers\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Inventory\Suppliers\Http\Resources\PurchaseOrderResource;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrderItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @group Inventory — Purchase Orders
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(IndexPurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated($orders, PurchaseOrderResource::class);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $po = DB::transaction(function () use ($validated, $request) {
            $po = PurchaseOrder::create([
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'created_by' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'subtotal' => round($line['quantity'] * $line['unit_cost'], 2),
                ]);
            }

            $po->recalculateTotal();

            return $po->load(['items.ingredient', 'supplier']);
        });

        return ApiResponse::created(new PurchaseOrderResource($po), 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success(
            new PurchaseOrderResource($purchaseOrder->load(['items.ingredient', 'supplier', 'branch'])),
        );
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->status !== 'draft') {
            return ApiResponse::error('Only draft orders can be submitted.', 'INVALID_PO_STATUS', 422);
        }

        $purchaseOrder->update([
            'status' => 'ordered',
            'ordered_at' => now(),
        ]);

        return ApiResponse::success(new PurchaseOrderResource($purchaseOrder), 'Purchase order submitted to supplier.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->stock->receivePurchaseOrder($purchaseOrder, $request->user());

            return ApiResponse::success(new PurchaseOrderResource($po), 'Purchase order received. Stock updated.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PO_STATUS', 422);
        }
    }
}
