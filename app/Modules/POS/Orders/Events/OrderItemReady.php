<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Events;

use App\Modules\POS\Orders\Models\OrderItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly OrderItem $item) {}

    public function broadcastOn(): array
    {
        $order = $this->item->order;

        return [
            new PrivateChannel("branch.{$order->branch_id}"),
            new PrivateChannel("orders.{$order->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.item.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->item->order_id,
            'item_id' => $this->item->id,
            'item_name_ar' => $this->item->item_name_ar,
            'quantity' => $this->item->quantity,
            'status' => $this->item->status,
            'cooked_at' => $this->item->cooked_at?->toISOString(),
        ];
    }
}
