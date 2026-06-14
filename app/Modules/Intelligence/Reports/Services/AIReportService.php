<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Reports\Services;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AIReportService
{
    /**
     * @return array<string, mixed>
     */
    public function weeklySummary(?int $branchId = null, ?Carbon $weekStart = null): array
    {
        $weekStart = ($weekStart ?? now()->startOfWeek())->copy()->startOfDay();
        $weekEnd = $weekStart->copy()->endOfWeek()->endOfDay();
        $prevStart = $weekStart->copy()->subWeek();
        $prevEnd = $weekEnd->copy()->subWeek();

        $current = $this->periodMetrics($weekStart, $weekEnd, $branchId);
        $previous = $this->periodMetrics($prevStart, $prevEnd, $branchId);

        $topItems = $this->topItems($weekStart, $weekEnd, $branchId, 5);
        $insights = $this->buildInsights($current, $previous, $topItems);

        return [
            'period' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
            ],
            'summary' => [
                'total_orders' => $current['total_orders'],
                'paid_orders' => $current['paid_orders'],
                'total_revenue' => $current['revenue'],
                'avg_ticket' => $current['avg_ticket'],
                'cancelled_orders' => $current['cancelled_orders'],
            ],
            'comparison' => [
                'revenue_change_pct' => $this->percentChange($previous['revenue'], $current['revenue']),
                'orders_change_pct' => $this->percentChange($previous['paid_orders'], $current['paid_orders']),
                'avg_ticket_change_pct' => $this->percentChange($previous['avg_ticket'], $current['avg_ticket']),
                'previous_week_revenue' => $previous['revenue'],
            ],
            'channels' => $current['channels'],
            'top_items' => $topItems,
            'insights' => $insights,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodMetrics(Carbon $start, Carbon $end, ?int $branchId): array
    {
        $orders = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->get();

        $paid = $orders->where('status', 'paid');
        $revenue = (float) $paid->sum('total');
        $paidCount = $paid->count();

        return [
            'total_orders' => $orders->count(),
            'paid_orders' => $paidCount,
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'revenue' => $revenue,
            'avg_ticket' => $paidCount > 0 ? round($revenue / $paidCount, 2) : 0.0,
            'channels' => $paid->groupBy('channel')->map(fn (Collection $group) => [
                'orders' => $group->count(),
                'revenue' => round((float) $group->sum('total'), 2),
            ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topItems(Carbon $start, Carbon $end, ?int $branchId, int $limit): array
    {
        return OrderItem::query()
            ->whereHas('order', fn ($q) => $q
                ->where('status', 'paid')
                ->whereBetween('created_at', [$start, $end])
                ->when($branchId, fn ($q2, $id) => $q2->where('branch_id', $id))
            )
            ->with('menuItem:id,name_ar')
            ->selectRaw('menu_item_id, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue')
            ->groupBy('menu_item_id')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'menu_item_id' => $row->menu_item_id,
                'name_ar' => $row->menuItem?->name_ar,
                'total_qty' => (int) $row->total_qty,
                'total_revenue' => round((float) $row->total_revenue, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @param  list<array<string, mixed>>  $topItems
     * @return list<array{severity: string, message_ar: string, message_en: string}>
     */
    private function buildInsights(array $current, array $previous, array $topItems): array
    {
        $insights = [];

        $revenueChange = $this->percentChange($previous['revenue'], $current['revenue']);

        if ($revenueChange !== null && $revenueChange <= -10) {
            $insights[] = [
                'severity' => 'warning',
                'message_ar' => "انخفضت الإيرادات بنسبة {$revenueChange}% مقارنة بالأسبوع الماضي. راجع العروض الترويجية وساعات الذروة.",
                'message_en' => "Revenue dropped {$revenueChange}% vs last week. Review promotions and peak-hour staffing.",
            ];
        } elseif ($revenueChange !== null && $revenueChange >= 10) {
            $insights[] = [
                'severity' => 'positive',
                'message_ar' => "ارتفعت الإيرادات بنسبة {$revenueChange}% — أداء ممتاز هذا الأسبوع.",
                'message_en' => "Revenue grew {$revenueChange}% — strong week.",
            ];
        }

        $aggregatorChannels = array_keys(config('intelligence.aggregators', []));
        $aggregatorRevenue = 0.0;
        $totalRevenue = max(0.01, (float) $current['revenue']);

        foreach ($aggregatorChannels as $channel) {
            $aggregatorRevenue += (float) ($current['channels'][$channel]['revenue'] ?? 0);
        }

        $aggregatorShare = round(($aggregatorRevenue / $totalRevenue) * 100, 1);

        if ($aggregatorShare >= 35) {
            $insights[] = [
                'severity' => 'warning',
                'message_ar' => "منصات التوصيل تمثل {$aggregatorShare}% من الإيرادات — العمولات تؤثر على هامش الربح. شجّع الطلب المباشر عبر QR أو واتساب.",
                'message_en' => "Aggregators account for {$aggregatorShare}% of revenue — commissions hurt margins. Push direct QR/WhatsApp ordering.",
            ];
        }

        if ($current['cancelled_orders'] > 0 && $current['total_orders'] > 0) {
            $cancelRate = round(($current['cancelled_orders'] / $current['total_orders']) * 100, 1);

            if ($cancelRate >= 8) {
                $insights[] = [
                    'severity' => 'warning',
                    'message_ar' => "معدل الإلغاء {$cancelRate}% — راجع أسباب الإلغاء في المطبخ والتوصيل.",
                    'message_en' => "Cancellation rate is {$cancelRate}% — investigate kitchen and delivery bottlenecks.",
                ];
            }
        }

        if ($topItems !== []) {
            $top = $topItems[0];
            $insights[] = [
                'severity' => 'info',
                'message_ar' => "الأكثر مبيعاً: {$top['name_ar']} ({$top['total_qty']} وحدة). تأكد من توفر المكونات.",
                'message_en' => "Top seller: {$top['name_ar']} ({$top['total_qty']} units). Ensure ingredient stock.",
            ];
        }

        if ($insights === []) {
            $insights[] = [
                'severity' => 'info',
                'message_ar' => 'لا توجد تنبيهات حرجة هذا الأسبوع — استمر في مراقبة الأداء اليومي.',
                'message_en' => 'No critical alerts this week — keep monitoring daily performance.',
            ];
        }

        return $insights;
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
