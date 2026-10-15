<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Services;

use App\Models\User;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Finance\Models\Expense;
use App\Shared\Support\Authorization\BranchAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Spreads approved operating expenses across items sold in the same period.
 * Recipe food cost is left unchanged.
 */
class ItemCostAllocationService
{
    public const METHOD = 'quantity_sold';

    /**
     * @return array{
     *     branch_id: int|null,
     *     start_date: string,
     *     end_date: string,
     *     method: string,
     *     overhead_pool: float,
     *     units_sold: float,
     *     overhead_per_unit: float,
     *     categories: list<array{category_id: int, category_name: string|null, count: int, total: float}>
     * }
     */
    public function summarizeForUser(User $user, int|string|null $branchId, ?string $startDate, ?string $endDate): array
    {
        $start = Carbon::parse($startDate ?: now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($endDate ?: now()->endOfMonth()->toDateString())->endOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }

        return $this->summarize(
            BranchAccess::filterBranchId($user, $branchId),
            $start,
            $end,
        );
    }

    /**
     * @param  array<string, mixed>  $foodCost
     * @return array<string, mixed>
     */
    public function decorateFoodCost(array $foodCost, User $user, int|string|null $branchId, ?string $startDate, ?string $endDate): array
    {
        $summary = $this->summarizeForUser($user, $branchId, $startDate, $endDate);
        $food = round((float) $foodCost['total_cost'], 4);
        $overhead = round((float) $summary['overhead_per_unit'], 4);
        $full = round($food + $overhead, 4);
        $price = (float) $foodCost['menu_price'];

        return [
            ...$foodCost,
            'food_cost' => $food,
            'overhead_cost' => $overhead,
            'full_cost' => $full,
            'true_margin' => $price > 0
                ? round((($price - $full) / $price) * 100, 2)
                : null,
            'overhead' => $summary,
        ];
    }

    /**
     * @return array{
     *     food_cost: float,
     *     overhead_cost: float,
     *     full_cost: float,
     *     food_cost_total: float,
     *     overhead_cost_total: float,
     *     full_cost_total: float,
     *     true_margin: float|null
     * }
     */
    public function pricedLine(float $foodUnitCost, float $quantity, float $revenue, float $overheadPerUnit): array
    {
        $foodUnit = round($foodUnitCost, 2);
        $overheadUnit = round($overheadPerUnit, 4);
        $foodTotal = round($foodUnit * $quantity, 2);
        $overheadTotal = round($overheadUnit * $quantity, 2);
        $fullTotal = round($foodTotal + $overheadTotal, 2);

        return [
            'food_cost' => $foodUnit,
            'overhead_cost' => $overheadUnit,
            'full_cost' => round($foodUnit + $overheadUnit, 4),
            'food_cost_total' => $foodTotal,
            'overhead_cost_total' => $overheadTotal,
            'full_cost_total' => $fullTotal,
            'true_margin' => $revenue > 0
                ? round((($revenue - $fullTotal) / $revenue) * 100, 2)
                : null,
        ];
    }

    /**
     * @return array{
     *     branch_id: int|null,
     *     start_date: string,
     *     end_date: string,
     *     method: string,
     *     overhead_pool: float,
     *     units_sold: float,
     *     overhead_per_unit: float,
     *     categories: list<array{category_id: int, category_name: string|null, count: int, total: float}>
     * }
     */
    public function summarize(?int $branchId, CarbonInterface $start, CarbonInterface $end): array
    {
        $expenses = Expense::query()
            ->where('status', 'approved')
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->whereDate('expense_date', '<=', $end->toDateString())
            ->whereHas('category', fn ($query) => $query->where('allocates_to_item_cost', true))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->with('category:id,name')
            ->get();

        $unitsSold = (float) OrderItem::query()
            ->whereNull('parent_id')
            ->whereHas('order', fn ($query) => $query
                ->where('status', 'paid')
                ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            )
            ->sum('quantity');

        $pool = round((float) $expenses->sum('amount'), 2);
        $categories = [];

        foreach ($expenses as $expense) {
            $categoryId = (int) $expense->expense_category_id;
            $categories[$categoryId] ??= [
                'category_id' => $categoryId,
                'category_name' => $expense->category?->name,
                'count' => 0,
                'total' => 0.0,
            ];
            $categories[$categoryId]['count']++;
            $categories[$categoryId]['total'] = round(
                $categories[$categoryId]['total'] + (float) $expense->amount,
                2,
            );
        }

        $categoryRows = array_values($categories);
        usort(
            $categoryRows,
            fn (array $left, array $right): int => strcmp((string) $left['category_name'], (string) $right['category_name']),
        );

        return [
            'branch_id' => $branchId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'method' => self::METHOD,
            'overhead_pool' => $pool,
            'units_sold' => round($unitsSold, 4),
            'overhead_per_unit' => $unitsSold > 0 ? round($pool / $unitsSold, 4) : 0.0,
            'categories' => $categoryRows,
        ];
    }
}
