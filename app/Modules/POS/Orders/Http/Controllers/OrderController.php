<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Orders\Http\Requests\IndexOrderRequest;
use App\Modules\POS\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\POS\Orders\Http\Requests\UpdateOrderRequest;
use App\Modules\POS\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\POS\Orders\Http\Resources\OrderResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Orders
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderPlacementService $orderPlacement) {}

    public function index(IndexOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $orders = Order::query()
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['table_id'] ?? null, fn ($q, $id) => $q->where('floor_table_id', $id))
            ->when($validated['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($validated['fulfillment_type'] ?? null, fn ($q, $type) => $q->where('fulfillment_type', $type))
            ->with(['items', 'table', 'waiter'])
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated($orders, OrderResource::class);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $order = $this->orderPlacement->place(
                branchId: (int) $validated['branch_id'],
                channel: $validated['channel'],
                items: $validated['items'],
                floorTableId: $validated['floor_table_id'] ?? null,
                waiterId: $request->user()->id,
                customerId: $validated['customer_id'] ?? null,
                notes: $validated['notes'] ?? null,
                deliveryAddress: $validated['delivery_address'] ?? null,
                fulfillmentType: $validated['fulfillment_type'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ORDER_VALIDATION_FAILED', 422);
        }

        return ApiResponse::created(new OrderResource($order), 'Order placed.');
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success(new OrderResource($order->load(['items.menuItem', 'table', 'waiter', 'payment', 'customer', 'rider'])));
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        $order->update($validated);

        return ApiResponse::success(new OrderResource($order), 'Order updated.');
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        if (! $order->canTransitionTo($validated['status'])) {
            return ApiResponse::error(
                "Cannot transition order from '{$order->status}' to '{$validated['status']}'.",
                'INVALID_STATUS_TRANSITION',
                422,
            );
        }

        $order->update(['status' => $validated['status']]);

        if ($validated['status'] === 'completed' && $order->floor_table_id) {
            FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
        }

        if ($validated['status'] === 'cancelled') {
            AuditLogger::log('order.cancelled', $order);
        }

        return ApiResponse::success(new OrderResource($order), 'Order status updated.');
    }
}
