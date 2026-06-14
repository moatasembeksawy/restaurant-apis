<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Http\Requests\StoreOrderItemRequest;
use App\Modules\POS\Orders\Http\Resources\OrderItemResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Order Items
 */
class OrderItemController extends Controller
{
    public function store(StoreOrderItemRequest $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending', 'active'])) {
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

        return ApiResponse::created(new OrderItemResource($item), 'Item added to order.');
    }

    public function destroy(Order $order, OrderItem $item): JsonResponse
    {
        if ($item->order_id !== $order->id) {
            return ApiResponse::error('Item does not belong to this order.', 'ITEM_NOT_FOUND', 404);
        }

        if (! in_array($order->status, ['pending', 'active'])) {
            return ApiResponse::error('Cannot remove items from an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        $item->delete();
        $order->recalculateTotals();

        return ApiResponse::noContent();
    }
}
