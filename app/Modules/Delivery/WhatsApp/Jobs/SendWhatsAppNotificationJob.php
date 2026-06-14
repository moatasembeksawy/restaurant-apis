<?php

declare(strict_types=1);

namespace App\Modules\Delivery\WhatsApp\Jobs;

use App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $type,
        public readonly ?int $paymentId = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(WhatsAppNotificationService $whatsapp): void
    {
        $this->order->loadMissing('customer', 'tenant');
        app()->instance('tenant', $this->order->tenant);

        try {
            match ($this->type) {
                'order_confirmed' => $whatsapp->sendOrderConfirmed($this->order),
                'order_ready' => $whatsapp->sendOrderReady($this->order),
                'receipt' => $whatsapp->sendReceipt(
                    $this->order,
                    Payment::query()->findOrFail($this->paymentId),
                ),
                default => throw new RuntimeException("Unknown WhatsApp notification type: {$this->type}"),
            };
        } catch (RuntimeException $e) {
            Log::warning('WhatsApp notification failed', [
                'type' => $this->type,
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
