<?php

declare(strict_types=1);

namespace App\Modules\POS\Kitchen\Http\Controllers;

use App\Modules\Delivery\WhatsApp\Jobs\SendWhatsAppNotificationJob;
use App\Modules\POS\Kitchen\Http\Requests\KitchenQueueRequest;
use App\Modules\POS\Kitchen\Http\Resources\KitchenQueueResource;
use App\Modules\POS\Orders\Events\OrderItemReady;
use App\Modules\POS\Orders\Events\OrderReady;
use App\Modules\POS\Orders\Http\Resources\OrderItemResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Kitchen Display
 */
class KitchenController extends Controller
{
    /**
     * Kitchen queue
     *
     * Returns all active orders with pending/cooking items.
     * This is the REST fallback — the primary path is WebSocket via Reverb.
     */
    public function queue(KitchenQueueRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $orders = Order::query()
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->whereIn('status', ['active', 'cooking'])
            ->with(['items' => fn ($q) => $q->whereIn('status', ['pending', 'cooking']), 'table'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success(KitchenQueueResource::collection($orders));
    }

    /**
     * Mark item as done
     *
     * Marks an order item as ready. When all items in an order are ready,
     * the order status automatically transitions to 'ready'.
     */
    public function markDone(OrderItem $item): JsonResponse
    {
        $item->update([
            'status' => 'ready',
            'cooked_at' => now(),
        ]);

        $order = $item->order()->with('table')->first();
        $allReady = $order->items()
            ->whereNotIn('status', ['ready', 'served', 'cancelled'])
            ->doesntExist();

        if ($allReady) {
            $order->update(['status' => 'ready']);
            $order = $order->fresh(['table', 'customer', 'tenant']);
            broadcast(new OrderReady($order))->toOthers();

            if ($order->customer_id && $order->tenant->whatsapp_phone_number_id && $order->tenant->hasFeature('whatsapp_ordering')) {
                SendWhatsAppNotificationJob::dispatch($order, 'order_ready');
            }
        } else {
            $order->update(['status' => 'cooking']);
        }

        broadcast(new OrderItemReady($item->fresh()))->toOthers();

        AuditLogger::log('kitchen.item_ready', $item, [
            'order_id' => $order->id,
            'item_name_ar' => $item->item_name_ar,
        ]);

        return ApiResponse::success(new OrderItemResource($item), 'Item marked as ready.');
    }
}
