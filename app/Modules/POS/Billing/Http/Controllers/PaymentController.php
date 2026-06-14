<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Payments & Billing
 */
class PaymentController extends Controller
{
    public function settle(Request $request, Order $order): JsonResponse
    {
        if ($order->status === 'paid') {
            return ApiResponse::error('This order has already been paid.', 'ORDER_ALREADY_PAID', 422);
        }

        if (! in_array($order->status, ['active', 'cooking', 'ready', 'completed'])) {
            return ApiResponse::error('Order must be active or completed before payment.', 'ORDER_NOT_PAYABLE', 422);
        }

        $validated = $request->validate([
            'method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu,split'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['discount_type']) && isset($validated['discount_value'])) {
            $discount = $validated['discount_type'] === 'percentage'
                ? round((float) $order->subtotal * ((float) $validated['discount_value'] / 100), 2)
                : (float) $validated['discount_value'];

            $order->update([
                'discount' => $discount,
            ]);
            $order->recalculateTotals();
        }

        $changeDue = null;
        if ($validated['method'] === 'cash' && isset($validated['cash_tendered'])) {
            $changeDue = max(0, $validated['cash_tendered'] - $validated['amount']);
        }

        $payment = Payment::create([
            ...$validated,
            'order_id' => $order->id,
            'cashier_id' => $request->user()->id,
            'change_due' => $changeDue,
        ]);

        $order->update(['status' => 'paid']);

        if ($order->floor_table_id) {
            FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
        }

        $invoice = Invoice::create([
            'payment_id' => $payment->id,
            'eta_status' => 'pending',
        ]);

        SubmitETAInvoiceJob::dispatch($invoice);

        AuditLogger::log('payment.settled', $order, [
            'payment_id' => $payment->id,
            'method' => $payment->method,
            'amount' => $payment->amount,
            'discount' => $order->discount,
        ]);

        return ApiResponse::success([
            'payment' => $payment->load('invoice'),
            'invoice' => $invoice,
            'change_due' => $changeDue,
        ], 'Payment settled successfully.');
    }
}
