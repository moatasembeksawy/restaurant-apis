<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Events\OrderItemAdded;
use App\Modules\POS\Orders\Http\Requests\StoreOrderItemRequest;
use App\Modules\POS\Orders\Http\Requests\UpdateOrderItemRequest;
use App\Modules\POS\Orders\Http\Resources\OrderItemResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Broadcasting\SafeBroadcast;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * @group Order Items
 */
class OrderItemController extends Controller
{
    public function store(StoreOrderItemRequest $request, Order $order): JsonResponse
    {
        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot add items to an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        $validated = $request->validated();

        $menuItem = MenuItem::findOrFail($validated['menu_item_id']);

        $item = $order->items()->create([
            'menu_item_id' => $menuItem->id,
            'item_name_ar' => $menuItem->name_ar,
            'unit_price' => $menuItem->price,
            'quantity' => $validated['quantity'],
            'subtotal' => $menuItem->price * $validated['quantity'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        $order->recalculateTotals();

        if ($order->status === 'ready') {
            $order->update(['status' => 'cooking']);
        }

        SafeBroadcast::toOthers(new OrderItemAdded($item->fresh('order.table')));

        return ApiResponse::created(new OrderItemResource($item), 'Item added to order.');
    }

    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item): JsonResponse
    {
        if ($item->order_id !== $order->id) {
            return ApiResponse::error('Item does not belong to this order.', 'ITEM_NOT_FOUND', 404);
        }

        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot update items on an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        if (! in_array($item->status, ['pending', 'ready'], true)) {
            return ApiResponse::error('Cannot update an item that is already '.$item->status.'.', 'ITEM_NOT_EDITABLE', 422);
        }

        $validated = $request->validated();
        $quantity = (int) $validated['quantity'];
        $previousQuantity = (int) $item->quantity;

        $attributes = [
            'quantity' => $quantity,
            'subtotal' => (float) $item->unit_price * $quantity,
        ];

        if (array_key_exists('notes', $validated)) {
            $attributes['notes'] = $validated['notes'];
        }

        if ($item->status === 'ready' && $quantity > $previousQuantity) {
            $attributes['status'] = 'pending';
            $attributes['cooked_at'] = null;
        }

        $item->update($attributes);
        $order->recalculateTotals();

        if ($quantity > $previousQuantity && in_array($order->status, ['ready', 'cooking'], true)) {
            $order->update(['status' => 'cooking']);
        }

        return ApiResponse::success(new OrderItemResource($item->fresh()), 'Item updated.');
    }

    public function destroy(Order $order, OrderItem $item): JsonResponse|Response
    {
        if ($item->order_id !== $order->id) {
            return ApiResponse::error('Item does not belong to this order.', 'ITEM_NOT_FOUND', 404);
        }

        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot remove items from an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        if ($item->status !== 'pending') {
            return ApiResponse::error('Cannot remove an item that is already '.$item->status.'.', 'ITEM_NOT_EDITABLE', 422);
        }

        $item->delete();
        $order->recalculateTotals();

        return ApiResponse::noContent();
    }
}
