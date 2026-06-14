<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Models\PurchaseOrder;
use App\Modules\Inventory\Stock\Models\PurchaseOrderItem;
use App\Modules\Inventory\Stock\Services\StockService;
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

    public function index(Request $request): JsonResponse
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::success($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

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

        return ApiResponse::created($po, 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($purchaseOrder->load(['items.ingredient', 'supplier', 'branch']));
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

        return ApiResponse::success($purchaseOrder, 'Purchase order submitted to supplier.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->stock->receivePurchaseOrder($purchaseOrder, $request->user());

            return ApiResponse::success($po, 'Purchase order received. Stock updated.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PO_STATUS', 422);
        }
    }
}
