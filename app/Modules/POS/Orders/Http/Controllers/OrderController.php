<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Orders
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderPlacementService $orderPlacement) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('table_id'), fn ($q, $id) => $q->where('floor_table_id', $id))
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->with(['items', 'table', 'waiter'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', '25'));

        return ApiResponse::success($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
            'channel' => ['required', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'notes' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $order = $this->orderPlacement->place(
            branchId: (int) $validated['branch_id'],
            channel: $validated['channel'],
            items: $validated['items'],
            floorTableId: $validated['floor_table_id'] ?? null,
            waiterId: $request->user()->id,
            customerId: $validated['customer_id'] ?? null,
            notes: $validated['notes'] ?? null,
            deliveryAddress: $validated['delivery_address'] ?? null,
        );

        return ApiResponse::created($order, 'Order placed.');
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success($order->load(['items.menuItem', 'table', 'waiter', 'payment', 'customer', 'rider']));
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $order->update($validated);

        return ApiResponse::success($order, 'Order updated.');
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,cooking,ready,completed,paid,cancelled'],
        ]);

        if (! $order->canTransitionTo($validated['status'])) {
            return ApiResponse::error(
                "Cannot transition order from '{$order->status}' to '{$validated['status']}'.",
                'INVALID_STATUS_TRANSITION',
                422,
            );
        }

        $order->update(['status' => $validated['status']]);

        if ($validated['status'] === 'completed' && $order->floor_table_id) {
            \App\Modules\POS\Tables\Models\FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
        }

        if ($validated['status'] === 'cancelled') {
            \App\Shared\Support\Audit\AuditLogger::log('order.cancelled', $order);
        }

        return ApiResponse::success($order, 'Order status updated.');
    }
}
