<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Analytics\Services;

use App\Modules\POS\Orders\Models\Order;
use Carbon\Carbon;

class AggregatorAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function compare(
        ?int $branchId = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): array {
        $start = ($startDate ?? now()->startOfMonth())->copy()->startOfDay();
        $end = ($endDate ?? now())->copy()->endOfDay();

        $orders = Order::query()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->get();

        $aggregatorConfig = config('intelligence.aggregators', []);
        $ownChannels = config('intelligence.own_channels', []);

        $channels = [];
        $totals = [
            'gross_revenue' => 0.0,
            'estimated_commission' => 0.0,
            'net_revenue' => 0.0,
            'orders' => 0,
        ];

        foreach ($orders->groupBy('channel') as $channel => $group) {
            $gross = round((float) $group->sum('total'), 2);
            $count = $group->count();
            $commissionPct = (float) ($aggregatorConfig[$channel]['commission_pct'] ?? 0);
            $commission = round($gross * ($commissionPct / 100), 2);
            $net = round($gross - $commission, 2);

            $channels[$channel] = [
                'label_ar' => $aggregatorConfig[$channel]['label_ar'] ?? $this->channelLabel($channel),
                'orders' => $count,
                'gross_revenue' => $gross,
                'commission_pct' => $commissionPct,
                'estimated_commission' => $commission,
                'net_revenue' => $net,
                'is_aggregator' => array_key_exists($channel, $aggregatorConfig),
            ];

            $totals['gross_revenue'] += $gross;
            $totals['estimated_commission'] += $commission;
            $totals['net_revenue'] += $net;
            $totals['orders'] += $count;
        }

        $aggregatorGross = collect($channels)
            ->where('is_aggregator', true)
            ->sum('gross_revenue');

        $ownGross = collect($channels)
            ->filter(fn (array $row, string $ch) => in_array($ch, $ownChannels, true))
            ->sum('gross_revenue');

        $totalGross = max(0.01, $totals['gross_revenue']);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'totals' => [
                ...$totals,
                'gross_revenue' => round($totals['gross_revenue'], 2),
                'estimated_commission' => round($totals['estimated_commission'], 2),
                'net_revenue' => round($totals['net_revenue'], 2),
            ],
            'channels' => collect($channels)
                ->sortByDesc('gross_revenue')
                ->values()
                ->all(),
            'comparison' => [
                'aggregator_share_pct' => round(($aggregatorGross / $totalGross) * 100, 1),
                'own_channels_share_pct' => round(($ownGross / $totalGross) * 100, 1),
                'aggregator_gross' => round($aggregatorGross, 2),
                'own_channels_gross' => round($ownGross, 2),
                'commission_saved_if_own_pct' => $aggregatorGross > 0
                    ? round((collect($channels)->where('is_aggregator', true)->avg('commission_pct') ?? 0), 1)
                    : 0,
            ],
        ];
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'dine_in' => 'صالة',
            'qr' => 'QR',
            'whatsapp' => 'واتساب',
            'own_delivery' => 'توصيل خاص',
            default => $channel,
        };
    }
}
