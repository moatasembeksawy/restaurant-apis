<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Jobs;

use App\Models\User;
use App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService;
use App\Modules\POS\Billing\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyETAInvoiceFailureJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('notifications');
    }

    public function handle(WhatsAppNotificationService $whatsapp): void
    {
        $payment = $this->invoice->payment()->with('order.tenant')->first();

        if (! $payment) {
            return;
        }

        $tenant = $payment->order->tenant;
        app()->instance('tenant', $tenant);

        $owner = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner?->phone) {
            return;
        }

        $whatsapp->sendEtaFailureAlert(
            phone: $owner->phone,
            orderId: $payment->order_id,
            error: (string) ($this->invoice->eta_response['error'] ?? 'Submission failed'),
        );
    }
}
