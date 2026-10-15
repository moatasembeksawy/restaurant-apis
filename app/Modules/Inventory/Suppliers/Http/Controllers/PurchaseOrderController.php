<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Controllers;

use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\Inventory\Suppliers\Http\Requests\IndexPurchaseOrderRequest;
use App\Modules\Inventory\Suppliers\Http\Requests\ReceivePurchaseOrderRequest;
use App\Modules\Inventory\Suppliers\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Inventory\Suppliers\Http\Resources\PurchaseOrderResource;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrderItem;
use App\Shared\Infrastructure\PrintJob\EscPosBuilder;
use App\Shared\Support\Authorization\BranchAccess;
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
    public function __construct(
        private readonly StockService $stock,
        private readonly EscPosBuilder $escPos,
    ) {}

    public function index(IndexPurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'branch:id,name']);
        BranchAccess::constrain($orders, $request->user());

        $orders = $orders
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

    /**
     * Printable purchase list bytes for a thermal print bridge.
     */
    public function print(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['items.ingredient', 'supplier', 'branch']);

        return ApiResponse::success([
            'purchase_order_id' => $purchaseOrder->id,
            'format' => 'escpos',
            'encoding' => 'binary',
            'bytes' => base64_encode($this->escPos->buildPurchaseOrder($purchaseOrder)),
        ]);
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

    /**
     * Record the quantities and costs received for a submitted purchase order.
     */
    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->stock->receivePurchaseOrder(
                $purchaseOrder,
                $request->validated('items'),
                $request->user(),
            );

            return ApiResponse::success(new PurchaseOrderResource($po), 'Purchase order received and ready for completion.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PO_STATUS', 422);
        }
    }

    /**
     * Complete a checked purchase order and add its received items to stock.
     */
    public function complete(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->stock->completePurchaseOrder($purchaseOrder, $request->user());

            return ApiResponse::success(new PurchaseOrderResource($po), 'Purchase order completed. Stock updated.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PO_STATUS', 422);
        }
    }
}
