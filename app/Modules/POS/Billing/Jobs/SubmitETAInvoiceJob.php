<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Jobs;

use App\Modules\POS\Billing\Models\Invoice;
use App\Shared\Infrastructure\ETA\ETAAdapterInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitETAInvoiceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum number of attempts before the job is marked as failed. */
    public int $tries = 3;

    /** Seconds to wait between retries (exponential: 60, 180, 540). */
    public int $backoff = 60;

    /** Unique key to prevent duplicate submissions for the same invoice. */
    public string $uniqueId;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('eta');
        $this->uniqueId = 'eta-invoice-'.$invoice->id;
    }

    public function handle(ETAAdapterInterface $eta): void
    {
        $this->invoice->update(['eta_status' => 'submitting']);

        $payment = $this->invoice->payment->load('order.items');
        $tenant = $payment->order->tenant;

        $document = $eta->buildInvoiceDocument($payment, $tenant);

        $token = $eta->getAccessToken(
            clientId: config('services.eta.client_id'),
            clientSecret: config('services.eta.client_secret'),
        );

        $result = $eta->submitInvoice($document, $token);

        $this->invoice->update([
            'eta_uuid' => $result['uuid'] ?? null,
            'eta_qr_url' => $result['qrUrl'] ?? null,
            'eta_status' => 'accepted',
            'eta_response' => $result,
            'submitted_at' => now(),
            'retry_count' => $this->attempts(),
        ]);

        Log::channel('stack')->info('ETA invoice submitted', [
            'invoice_id' => $this->invoice->id,
            'eta_uuid' => $result['uuid'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->invoice->update([
            'eta_status' => 'failed',
            'retry_count' => $this->attempts(),
            'eta_response' => ['error' => $exception->getMessage()],
        ]);

        Log::channel('stack')->error('ETA invoice submission failed', [
            'invoice_id' => $this->invoice->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // TODO: notify tenant owner via WhatsApp/push if all retries exhausted
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }
}
