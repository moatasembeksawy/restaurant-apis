<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Events;

use App\Modules\POS\Orders\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("branch.{$this->order->branch_id}"),
            new PrivateChannel("orders.{$this->order->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'table' => $this->order->table?->name,
            'status' => $this->order->status,
            'waiter_id' => $this->order->waiter_id,
        ];
    }
}
