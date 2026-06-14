<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Services;

use App\Modules\POS\Billing\Jobs\NotifyETAInvoiceFailureJob;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Billing\Models\Invoice;
use App\Shared\Infrastructure\ETA\ETACredentialResolver;
use App\Shared\Infrastructure\ETA\ETAAdapterInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class ETAService
{
    public function __construct(
        private readonly ETAAdapterInterface $eta,
        private readonly ETACredentialResolver $credentials,
    ) {}

    public function submit(Invoice $invoice): void
    {
        $invoice->update(['eta_status' => 'submitting']);

        $payment = $invoice->payment()->with('order.items', 'order.tenant')->firstOrFail();
        $tenant = $payment->order->tenant;

        $creds = $this->credentials->forTenant($tenant);

        if (! $creds->isConfigured()) {
            $invoice->update([
                'eta_status' => 'skipped',
                'eta_response' => ['reason' => 'ETA credentials not configured for this tenant.'],
            ]);

            return;
        }

        if (! $tenant->hasFeature('eta_invoice')) {
            $invoice->update([
                'eta_status' => 'skipped',
                'eta_response' => ['reason' => 'ETA invoicing is not enabled on this plan.'],
            ]);

            return;
        }

        try {
            $document = $this->eta->buildInvoiceDocument($payment, $tenant);
            $token = $this->eta->getAccessToken($creds->clientId, $creds->clientSecret);
            $result = $this->eta->submitInvoice($document, $token);

            $invoice->update([
                'eta_uuid' => $result['uuid'] ?? null,
                'eta_qr_url' => $result['qrUrl'] ?? null,
                'eta_status' => 'accepted',
                'eta_response' => $result,
                'submitted_at' => now(),
                'retry_count' => ($invoice->retry_count ?? 0) + 1,
            ]);

            Log::channel('stack')->info('ETA invoice submitted', [
                'invoice_id' => $invoice->id,
                'eta_uuid' => $result['uuid'] ?? null,
            ]);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function resubmit(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->eta_status, ['failed', 'skipped', 'pending'], true)) {
            throw new \InvalidArgumentException('Only failed, skipped, or pending invoices can be resubmitted.');
        }

        $invoice->update(['eta_status' => 'pending']);
        SubmitETAInvoiceJob::dispatch($invoice->fresh());

        return $invoice->fresh();
    }

    public function handleFailure(Invoice $invoice, Throwable $exception, int $attempts): void
    {
        $invoice->update([
            'eta_status' => 'failed',
            'retry_count' => $attempts,
            'eta_response' => ['error' => $exception->getMessage()],
        ]);

        Log::channel('stack')->error('ETA invoice submission failed', [
            'invoice_id' => $invoice->id,
            'error' => $exception->getMessage(),
            'attempts' => $attempts,
        ]);

        NotifyETAInvoiceFailureJob::dispatch($invoice->fresh());
    }
}
