<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Services\ETAService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group ETA Invoices
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly ETAService $eta) {}

    public function show(Invoice $invoice): JsonResponse
    {
        return ApiResponse::success($invoice->load('payment.order'));
    }

    public function resubmit(Request $request, Invoice $invoice): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'manager', 'cashier'], true)) {
            return ApiResponse::error('Unauthorized.', 'FORBIDDEN', 403);
        }

        try {
            $invoice = $this->eta->resubmit($invoice);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ETA_RESUBMIT_FAILED', 422);
        }

        return ApiResponse::success($invoice, 'ETA submission queued for retry.');
    }

    public function failed(): JsonResponse
    {
        $invoices = Invoice::query()
            ->whereIn('eta_status', ['failed', 'skipped'])
            ->with('payment.order')
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success($invoices);
    }
}
