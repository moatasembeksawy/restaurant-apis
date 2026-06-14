<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Events;

use App\Modules\POS\Orders\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("branch.{$this->order->branch_id}");
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'table' => $this->order->table?->name,
            'channel' => $this->order->channel,
            'items' => $this->order->items->map(fn ($item) => [
                'id' => $item->id,
                'name_ar' => $item->item_name_ar,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
            ])->all(),
            'created_at' => $this->order->created_at->toISOString(),
        ];
    }
}
