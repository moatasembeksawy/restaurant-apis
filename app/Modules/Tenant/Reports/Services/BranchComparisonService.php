<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Reports\Services;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Carbon\Carbon;
use InvalidArgumentException;

class BranchComparisonService
{
    /**
     * @return array<string, mixed>
     */
    public function compare(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        if (! $tenant->hasFeature('multi_branch')) {
            throw new InvalidArgumentException('Branch comparison requires Enterprise plan.');
        }

        $start = ($startDate ?? now()->startOfMonth())->copy()->startOfDay();
        $end = ($endDate ?? now())->copy()->endOfDay();

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $branchStats = $branches->map(function (Branch $branch) use ($start, $end): array {
            $orders = Order::query()
                ->where('branch_id', $branch->id)
                ->whereBetween('created_at', [$start, $end])
                ->get();

            $paid = $orders->where('status', 'paid');
            $revenue = (float) $paid->sum('total');
            $paidCount = $paid->count();

            return [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'name_ar' => $branch->name_ar,
                'total_orders' => $orders->count(),
                'paid_orders' => $paidCount,
                'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
                'revenue' => round($revenue, 2),
                'avg_ticket' => $paidCount > 0 ? round($revenue / $paidCount, 2) : 0.0,
                'channels' => $paid->groupBy('channel')->map(fn ($group) => [
                    'orders' => $group->count(),
                    'revenue' => round((float) $group->sum('total'), 2),
                ])->all(),
            ];
        })->values()->all();

        $totalRevenue = array_sum(array_column($branchStats, 'revenue'));

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'branches' => $branchStats,
            'totals' => [
                'branch_count' => count($branchStats),
                'paid_orders' => array_sum(array_column($branchStats, 'paid_orders')),
                'revenue' => round($totalRevenue, 2),
            ],
            'leader' => $this->leaderBranch($branchStats),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $branchStats
     * @return array<string, mixed>|null
     */
    private function leaderBranch(array $branchStats): ?array
    {
        if ($branchStats === []) {
            return null;
        }

        $leader = collect($branchStats)->sortByDesc('revenue')->first();

        return $leader ? [
            'branch_id' => $leader['branch_id'],
            'name' => $leader['name'],
            'revenue' => $leader['revenue'],
        ] : null;
    }
}
