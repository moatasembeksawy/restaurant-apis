<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Services;

use App\Models\User;
use App\Modules\Delivery\WhatsApp\Jobs\SendWhatsAppNotificationJob;
use App\Modules\Intelligence\Loyalty\Services\LoyaltyService;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentSettlementService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{payment: Payment, invoice: Invoice, change_due: ?float}
     */
    public function settle(Order $order, array $validated, User $cashier): array
    {
        if ($order->status === 'paid') {
            throw new InvalidArgumentException('This order has already been paid.');
        }

        if (! in_array($order->status, ['active', 'cooking', 'ready', 'completed'], true)) {
            throw new InvalidArgumentException('Order must be active or completed before payment.');
        }

        return DB::transaction(function () use ($order, $validated, $cashier): array {
            /** @var Tenant $tenant */
            $tenant = app('tenant');

            if (! empty($validated['discount_type']) && isset($validated['discount_value'])) {
                $discount = $validated['discount_type'] === 'percentage'
                    ? round((float) $order->subtotal * ((float) $validated['discount_value'] / 100), 2)
                    : (float) $validated['discount_value'];

                $order->update(['discount' => $discount]);
                $order->recalculateTotals();
                $order->refresh();
            }

            $loyaltyRedemption = null;

            if (
                ! empty($validated['loyalty_points'])
                && $tenant->hasFeature('loyalty')
                && $order->customer_id
            ) {
                $loyaltyRedemption = $this->loyalty->redeemForOrder(
                    $order,
                    (int) $validated['loyalty_points'],
                );
                $order->refresh();
            }

            $method = $validated['method'];
            $amount = (float) $validated['amount'];

            if ($method === 'split') {
                $this->validateSplitPayment($validated['splits'] ?? [], $order);
                $amount = collect($validated['splits'])->sum(fn ($s) => (float) $s['amount']);
            }

            if (round($amount, 2) !== round((float) $order->total, 2)) {
                throw new InvalidArgumentException(
                    'Payment amount must equal order total of '.number_format((float) $order->total, 2).'.',
                );
            }

            $changeDue = null;
            if ($method === 'cash' && isset($validated['cash_tendered'])) {
                $changeDue = max(0, (float) $validated['cash_tendered'] - $amount);
            }

            $payment = Payment::create([
                'method' => $method,
                'amount' => $amount,
                'cash_tendered' => $validated['cash_tendered'] ?? null,
                'change_due' => $changeDue,
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_reason' => $validated['discount_reason'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'order_id' => $order->id,
                'cashier_id' => $cashier->id,
            ]);

            if ($method === 'split') {
                foreach ($validated['splits'] as $split) {
                    PaymentSplit::create([
                        'payment_id' => $payment->id,
                        'method' => $split['method'],
                        'amount' => $split['amount'],
                        'reference' => $split['reference'] ?? null,
                    ]);
                }
            }

            $order->update(['status' => 'paid']);

            if ($order->floor_table_id) {
                FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
            }

            $invoice = Invoice::create([
                'payment_id' => $payment->id,
                'eta_status' => 'pending',
            ]);

            SubmitETAInvoiceJob::dispatch($invoice);

            if ($tenant->hasFeature('inventory')) {
                $this->stock->deductForOrder($order->load('items'));
            }

            if ($tenant->hasFeature('loyalty') && $order->customer_id) {
                $this->loyalty->accrueForOrder($order);
            }

            if (
                $tenant->hasFeature('whatsapp_ordering')
                && $order->customer_id
                && $tenant->whatsapp_phone_number_id
            ) {
                SendWhatsAppNotificationJob::dispatch($order->fresh(['customer']), 'receipt', $payment->id);
            }

            AuditLogger::log('payment.settled', $order, [
                'payment_id' => $payment->id,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'discount' => $order->discount,
                'loyalty_points_redeemed' => $loyaltyRedemption['points_redeemed'] ?? null,
                'split_count' => $method === 'split' ? count($validated['splits']) : null,
            ]);

            return [
                'payment' => $payment->load(['invoice', 'splits']),
                'invoice' => $invoice,
                'change_due' => $changeDue,
                'loyalty_redemption' => $loyaltyRedemption ? [
                    'points_redeemed' => $loyaltyRedemption['points_redeemed'],
                    'discount_egp' => $loyaltyRedemption['discount_egp'],
                    'balance' => $loyaltyRedemption['balance'],
                ] : null,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $splits
     */
    private function validateSplitPayment(array $splits, Order $order): void
    {
        if (count($splits) < 2) {
            throw new InvalidArgumentException('Split payment requires at least two payment methods.');
        }

        $total = 0.0;

        foreach ($splits as $split) {
            if (empty($split['method']) || ! isset($split['amount'])) {
                throw new InvalidArgumentException('Each split must include method and amount.');
            }

            if ((float) $split['amount'] <= 0) {
                throw new InvalidArgumentException('Each split amount must be greater than zero.');
            }

            $total += (float) $split['amount'];
        }

        if (round($total, 2) !== round((float) $order->total, 2)) {
            throw new InvalidArgumentException('Split amounts must sum to the order total.');
        }
    }
}
