<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Jobs;

use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Services\ETAService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SubmitETAInvoiceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public string $uniqueId;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('eta');
        $this->uniqueId = 'eta-invoice-'.$invoice->id;
    }

    public function handle(ETAService $etaService): void
    {
        $etaService->submit($this->invoice->fresh());
    }

    public function failed(Throwable $exception): void
    {
        app(ETAService::class)->handleFailure(
            $this->invoice->fresh(),
            $exception,
            $this->attempts(),
        );
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }
}
