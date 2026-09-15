<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Controllers;

use App\Modules\POS\Orders\Events\OrderItemAdded;
use App\Modules\POS\Orders\Http\Requests\StoreOrderItemRequest;
use App\Modules\POS\Orders\Http\Requests\UpdateOrderItemRequest;
use App\Modules\POS\Orders\Http\Resources\OrderItemResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Orders\Services\OrderLineService;
use App\Shared\Support\Broadcasting\SafeBroadcast;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Order Items
 */
class OrderItemController extends Controller
{
    public function __construct(private readonly OrderLineService $lines) {}

    public function store(StoreOrderItemRequest $request, Order $order): JsonResponse
    {
        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot add items to an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        try {
            $item = $this->lines->add($order, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ORDER_VALIDATION_FAILED', 422);
        }

        $order->recalculateTotals();

        if ($order->status === 'ready') {
            $order->update(['status' => 'cooking']);
        }

        SafeBroadcast::toOthers(new OrderItemAdded($item->fresh('order.table')));

        return ApiResponse::created(new OrderItemResource($item->load('children')), 'Item added to order.');
    }

    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item): JsonResponse
    {
        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot update items on an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        if (! in_array($item->status, ['pending', 'ready'], true)) {
            return ApiResponse::error('Cannot update an item that is already '.$item->status.'.', 'ITEM_NOT_EDITABLE', 422);
        }

        $validated = $request->validated();
        $previousQuantity = (int) $item->quantity;

        try {
            $item = $this->lines->update($order, $item, $validated);
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'does not belong') ? 'ITEM_NOT_FOUND' : 'ITEM_NOT_EDITABLE';
            $status = $code === 'ITEM_NOT_FOUND' ? 404 : 422;

            return ApiResponse::error($e->getMessage(), $code, $status);
        }

        $order->recalculateTotals();

        $quantity = (int) $item->quantity;
        if ($quantity > $previousQuantity && in_array($order->status, ['ready', 'cooking'], true)) {
            $order->update(['status' => 'cooking']);
        }

        return ApiResponse::success(new OrderItemResource($item->load('children')), 'Item updated.');
    }

    public function destroy(Order $order, OrderItem $item): JsonResponse|Response
    {
        if (! $order->canAddItems()) {
            return ApiResponse::error('Cannot remove items from an order with status: '.$order->status, 'ORDER_NOT_EDITABLE', 422);
        }

        if ($item->status !== 'pending' && ! $item->isPackageParent()) {
            return ApiResponse::error('Cannot remove an item that is already '.$item->status.'.', 'ITEM_NOT_EDITABLE', 422);
        }

        if ($item->isPackageParent() && $item->children()->where('status', '!=', 'pending')->exists()) {
            return ApiResponse::error('Cannot remove a package after kitchen has started it.', 'ITEM_NOT_EDITABLE', 422);
        }

        try {
            $this->lines->remove($order, $item);
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'does not belong') ? 'ITEM_NOT_FOUND' : 'ITEM_NOT_EDITABLE';
            $status = $code === 'ITEM_NOT_FOUND' ? 404 : 422;

            return ApiResponse::error($e->getMessage(), $code, $status);
        }

        $order->recalculateTotals();

        return ApiResponse::noContent();
    }
}
