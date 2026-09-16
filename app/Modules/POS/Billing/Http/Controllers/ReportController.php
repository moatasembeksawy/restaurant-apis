<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Billing\Http\Requests\CashSummaryReportRequest;
use App\Modules\POS\Billing\Http\Requests\DailyReportRequest;
use App\Modules\POS\Billing\Http\Requests\TopItemsReportRequest;
use App\Modules\POS\Billing\Http\Resources\ReportResource;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Reports
 */
class ReportController extends Controller
{
    public function daily(DailyReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $date = $validated['date'] ?? now()->toDateString();
        $query = Order::query()->whereDate('created_at', $date);
        BranchAccess::constrain($query, $request->user(), $validated['branch_id'] ?? null);

        $orders = $query->get();

        return ApiResponse::success(new ReportResource([
            'date' => $date,
            'total_orders' => $orders->count(),
            'paid_orders' => $orders->where('status', 'paid')->count(),
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'total_revenue' => $orders->where('status', 'paid')->sum('total'),
            'total_covers' => $orders->where('channel', 'dine_in')->count(),
            'channels' => $orders->groupBy('channel')->map(fn ($g) => [
                'count' => $g->count(),
                'revenue' => $g->where('status', 'paid')->sum('total'),
            ]),
        ]));
    }

    public function cashSummary(CashSummaryReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $date = $validated['date'] ?? now()->toDateString();
        $branchId = BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null);

        $payments = Payment::query()
            ->with('splits')
            ->whereHas('order', fn ($q) => $q
                ->whereDate('created_at', $date)
                ->when($branchId, fn ($q2, $id) => $q2->where('branch_id', $id))
            )
            ->get();

        /** @var array<string, array{count: int, total: float}> $byMethod */
        $byMethod = [];

        foreach ($payments as $payment) {
            if ($payment->method === 'split' && $payment->splits->isNotEmpty()) {
                foreach ($payment->splits as $split) {
                    $this->addMethodTotal($byMethod, (string) $split->method, (float) $split->amount);
                }

                continue;
            }

            $this->addMethodTotal($byMethod, (string) $payment->method, (float) $payment->amount);
        }

        foreach ($byMethod as &$row) {
            $row['total'] = round($row['total'], 2);
        }
        unset($row);

        return ApiResponse::success(new ReportResource([
            'date' => $date,
            'by_method' => $byMethod,
            'total_cash' => $byMethod['cash']['total'] ?? 0,
            'total_all_methods' => round((float) $payments->sum('amount'), 2),
            'total_discounts' => $payments->whereNotNull('discount_value')->sum('discount_value'),
        ]));
    }

    public function topItems(TopItemsReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $startDate = Carbon::parse($validated['start_date'] ?? now()->startOfWeek())->startOfDay();
        $endDate = Carbon::parse($validated['end_date'] ?? now())->endOfDay();
        $limit = (int) ($validated['limit'] ?? 10);
        $branchId = BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null);

        $items = OrderItem::query()
            ->whereNull('parent_id')
            ->whereHas('order', fn ($q) => $q
                ->where('status', 'paid')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($branchId, fn ($q2, $id) => $q2->where('branch_id', $id))
            )
            ->with('menuItem:id,name_ar,price,cost_price')
            ->selectRaw('menu_item_id, package_id, item_name_ar, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue')
            ->groupBy('menu_item_id', 'package_id', 'item_name_ar')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'package_id' => $item->package_id,
                'name_ar' => $item->item_name_ar ?: $item->menuItem?->name_ar,
                'total_qty' => (int) $item->total_qty,
                'total_revenue' => round((float) $item->total_revenue, 2),
                'profit_margin' => $item->menuItem?->profitMargin(),
            ])
            ->values()
            ->all();

        return ApiResponse::success(new ReportResource([
            'items' => $items,
        ]));
    }

    /**
     * @param  array<string, array{count: int, total: float}>  $byMethod
     */
    private function addMethodTotal(array &$byMethod, string $method, float $amount): void
    {
        $byMethod[$method] ??= ['count' => 0, 'total' => 0.0];
        $byMethod[$method]['count']++;
        $byMethod[$method]['total'] += $amount;
    }
}
