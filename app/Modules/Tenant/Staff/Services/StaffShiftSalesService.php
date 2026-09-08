<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Services;

use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentRefund;
use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Finance\Services\CashMovementService;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Illuminate\Support\Collection;

class StaffShiftSalesService
{
    public function __construct(
        private readonly CashMovementService $cashMovements,
    ) {}

    /** @return array<string, mixed> */
    public function summarize(StaffShift $shift): array
    {
        $payments = Payment::query()
            ->where('staff_shift_id', $shift->id)
            ->with(['splits', 'refund'])
            ->get();

        $refunds = PaymentRefund::query()
            ->where('staff_shift_id', $shift->id)
            ->with('payment.splits')
            ->get();

        $byMethod = $this->totalsByMethod($payments);
        $cashCollected = $this->cashCollected($shift->id, $payments);
        $cashRefunded = $this->cashRefunded($refunds);
        $movements = $this->cashMovements->totalsForShift($shift);

        $grossSales = round((float) $payments->sum('amount'), 2);
        $refundsTotal = round((float) $refunds->sum('amount'), 2);

        return [
            'orders_count' => $payments->count(),
            'gross_sales' => $grossSales,
            'refunds_count' => $refunds->count(),
            'refunds_total' => $refundsTotal,
            'net_sales' => round($grossSales - $refundsTotal, 2),
            'by_method' => $byMethod,
            'cash_collected' => $cashCollected,
            'cash_refunded' => $cashRefunded,
            'cash_movements' => $movements,
            'opening_float' => (string) $shift->opening_float,
            'expected_cash_in_drawer' => round(
                (float) $shift->opening_float + $cashCollected - $cashRefunded + $movements['net'],
                2,
            ),
            'transactions' => $this->transactions($shift, $payments, $refunds),
        ];
    }

    public function expectedCashInDrawer(StaffShift $shift): float
    {
        return (float) $this->summarize($shift)['expected_cash_in_drawer'];
    }

    /** @param  Collection<int, Payment>  $payments */
    private function totalsByMethod(Collection $payments): array
    {
        $totals = [];

        foreach ($payments as $payment) {
            if ($payment->method === 'split') {
                foreach ($payment->splits as $split) {
                    $method = $split->method;
                    $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + 1;
                    $totals[$method]['total'] = round(($totals[$method]['total'] ?? 0) + (float) $split->amount, 2);
                }

                continue;
            }

            $method = $payment->method;
            $totals[$method]['count'] = ($totals[$method]['count'] ?? 0) + 1;
            $totals[$method]['total'] = round(($totals[$method]['total'] ?? 0) + (float) $payment->amount, 2);
        }

        return $totals;
    }

    /** @param  Collection<int, Payment>  $payments */
    private function cashCollected(int $shiftId, Collection $payments): float
    {
        $cash = (float) $payments
            ->where('method', 'cash')
            ->sum('amount');

        $splitCash = (float) PaymentSplit::query()
            ->where('method', 'cash')
            ->whereHas('payment', fn ($query) => $query->where('staff_shift_id', $shiftId))
            ->sum('amount');

        return round($cash + $splitCash, 2);
    }

    /** @param  Collection<int, PaymentRefund>  $refunds */
    private function cashRefunded(Collection $refunds): float
    {
        $total = 0.0;

        foreach ($refunds as $refund) {
            $payment = $refund->payment;

            if (! $payment) {
                continue;
            }

            if ($payment->method === 'cash') {
                $total += (float) $refund->amount;

                continue;
            }

            if ($payment->method === 'split') {
                $cashPortion = (float) $payment->splits->where('method', 'cash')->sum('amount');
                $paymentTotal = (float) $payment->amount;

                if ($paymentTotal > 0 && $cashPortion > 0) {
                    $total += round((float) $refund->amount * ($cashPortion / $paymentTotal), 2);
                }
            }
        }

        return round($total, 2);
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentRefund>  $refunds
     * @return array<int, array<string, mixed>>
     */
    private function transactions(StaffShift $shift, Collection $payments, Collection $refunds): array
    {
        $transactions = $payments->map(fn (Payment $payment): array => [
            'kind' => 'payment',
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount' => (float) $payment->amount,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'occurred_at' => $payment->created_at?->toIso8601String(),
        ])->concat($refunds->map(fn (PaymentRefund $refund): array => [
            'kind' => 'refund',
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'amount' => (float) $refund->amount,
            'reason' => $refund->reason,
            'occurred_at' => $refund->created_at?->toIso8601String(),
        ]));

        $cashMovements = CashMovement::query()
            ->where('staff_shift_id', $shift->id)
            ->with('creator:id,name')
            ->get()
            ->map(fn (CashMovement $movement): array => [
                'kind' => 'cash_movement',
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'reason' => $movement->reason,
                'reference' => $movement->reference,
                'created_by' => $movement->created_by,
                'created_by_name' => $movement->creator?->name,
                'is_reversed' => $movement->reversed_at !== null,
                'reversal_of_id' => $movement->reversal_of_id,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ]);

        return $transactions
            ->concat($cashMovements)
            ->sortBy('occurred_at')
            ->values()
            ->all();
    }
}
