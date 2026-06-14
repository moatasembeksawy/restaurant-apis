<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Orders
 */
class OrderController extends Controller
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

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
        $this->planLimits->check('orders');

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
            'channel' => ['required', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $order = Order::create([
            'branch_id' => $validated['branch_id'],
            'floor_table_id' => $validated['floor_table_id'] ?? null,
            'waiter_id' => $request->user()->id,
            'channel' => $validated['channel'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        foreach ($validated['items'] as $itemData) {
            $menuItem = \App\Modules\POS\Menu\Models\MenuItem::findOrFail($itemData['menu_item_id']);
            $subtotal = $menuItem->price * $itemData['quantity'];

            $order->items()->create([
                'menu_item_id' => $menuItem->id,
                'item_name_ar' => $menuItem->name_ar,
                'unit_price' => $menuItem->price,
                'quantity' => $itemData['quantity'],
                'subtotal' => $subtotal,
                'status' => 'pending',
                'notes' => $itemData['notes'] ?? null,
            ]);
        }

        $order->recalculateTotals();

        if ($order->floor_table_id) {
            FloorTable::find($order->floor_table_id)?->update(['status' => 'occupied']);
        }

        $order->update(['status' => 'active']);

        // Broadcast to kitchen display
        broadcast(new \App\Modules\POS\Orders\Events\OrderPlaced($order->load('items')))->toOthers();

        AuditLogger::log('order.placed', $order, [
            'channel' => $order->channel,
            'total' => $order->total,
            'items_count' => $order->items->count(),
        ]);

        return ApiResponse::created($order->load('items'), 'Order placed.');
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success($order->load(['items.menuItem', 'table', 'waiter', 'payment']));
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
            FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
        }

        if ($validated['status'] === 'cancelled') {
            AuditLogger::log('order.cancelled', $order);
        }

        return ApiResponse::success($order, 'Order status updated.');
    }
}
