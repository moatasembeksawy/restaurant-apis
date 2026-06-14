<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Loyalty\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Models\LoyaltyTransaction;
use App\Modules\POS\Orders\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoyaltyService
{
    public function calculateEarnPoints(float $orderTotal): int
    {
        $perEgp = max(0.01, (float) config('intelligence.loyalty.points_per_egp', 10));

        return (int) floor($orderTotal / $perEgp);
    }

    public function redeemValue(int $points): float
    {
        return round($points * (float) config('intelligence.loyalty.redeem_value_egp', 0.25), 2);
    }

    public function accrueForOrder(Order $order): ?LoyaltyTransaction
    {
        if (! $order->customer_id) {
            return null;
        }

        $points = $this->calculateEarnPoints((float) $order->total);

        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $points): LoyaltyTransaction {
            $customer = Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
            $newBalance = $customer->loyalty_points + $points;

            $customer->update(['loyalty_points' => $newBalance]);

            return LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $newBalance,
                'monetary_value' => (float) $order->total,
                'notes' => "Earned from order #{$order->id}",
            ]);
        });
    }

    /**
     * @return array{points_redeemed: int, discount_egp: float, balance: int, transaction: LoyaltyTransaction}
     */
    public function redeemForOrder(Order $order, int $points): array
    {
        if (! $order->customer_id) {
            throw new InvalidArgumentException('Order has no customer for loyalty redemption.');
        }

        $customer = Customer::query()->findOrFail($order->customer_id);

        $result = $this->redeem(
            $customer,
            $points,
            "Redeemed on order #{$order->id}",
            $order->id,
        );

        $order->update([
            'discount' => (float) $order->discount + $result['discount_egp'],
        ]);
        $order->recalculateTotals();

        return $result;
    }

    /**
     * @return array{points_redeemed: int, discount_egp: float, balance: int, transaction: LoyaltyTransaction}
     */
    public function redeem(Customer $customer, int $points, ?string $notes = null, ?int $orderId = null): array
    {
        $minPoints = (int) config('intelligence.loyalty.min_redeem_points', 50);

        if ($points < $minPoints) {
            throw new InvalidArgumentException("Minimum redemption is {$minPoints} points.");
        }

        if ($points > $customer->loyalty_points) {
            throw new InvalidArgumentException('Insufficient loyalty points.');
        }

        return DB::transaction(function () use ($customer, $points, $notes, $orderId): array {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $newBalance = $locked->loyalty_points - $points;
            $discountEgp = $this->redeemValue($points);

            $locked->update(['loyalty_points' => $newBalance]);

            $transaction = LoyaltyTransaction::create([
                'customer_id' => $locked->id,
                'order_id' => $orderId,
                'type' => 'redeem',
                'points' => -$points,
                'balance_after' => $newBalance,
                'monetary_value' => $discountEgp,
                'notes' => $notes ?? 'Points redeemed at POS',
            ]);

            return [
                'points_redeemed' => $points,
                'discount_egp' => $discountEgp,
                'balance' => $newBalance,
                'transaction' => $transaction,
            ];
        });
    }

    public function profile(Customer $customer, int $historyLimit = 10): array
    {
        $transactions = LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit($historyLimit)
            ->get();

        return [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'points' => $customer->loyalty_points,
            'visit_count' => $customer->visit_count,
            'total_spent' => (float) $customer->total_spent,
            'redeem_value_egp' => $this->redeemValue($customer->loyalty_points),
            'min_redeem_points' => (int) config('intelligence.loyalty.min_redeem_points', 50),
            'points_per_egp' => (float) config('intelligence.loyalty.points_per_egp', 10),
            'recent_transactions' => $transactions->map(fn (LoyaltyTransaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'points' => $tx->points,
                'balance_after' => $tx->balance_after,
                'monetary_value' => $tx->monetary_value ? (float) $tx->monetary_value : null,
                'notes' => $tx->notes,
                'created_at' => $tx->created_at?->toIso8601String(),
            ]),
        ];
    }

    /** @return Collection<int, LoyaltyTransaction> */
    public function recentTransactions(Customer $customer, int $limit = 20): Collection
    {
        return LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
