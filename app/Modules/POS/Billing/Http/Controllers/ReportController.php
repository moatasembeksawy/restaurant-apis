<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Reports
 */
class ReportController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $branchId = $request->query('branch_id');

        $query = Order::query()
            ->whereDate('created_at', $date)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id));

        $orders = $query->get();

        return ApiResponse::success([
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
        ]);
    }

    public function cashSummary(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $branchId = $request->query('branch_id');

        $payments = \App\Modules\POS\Billing\Models\Payment::query()
            ->whereHas('order', fn ($q) => $q
                ->whereDate('created_at', $date)
                ->when($branchId, fn ($q2, $id) => $q2->where('branch_id', $id))
            )
            ->get();

        return ApiResponse::success([
            'date' => $date,
            'by_method' => $payments->groupBy('method')->map(fn ($g) => [
                'count' => $g->count(),
                'total' => $g->sum('amount'),
            ]),
            'total_cash' => $payments->where('method', 'cash')->sum('amount'),
            'total_all_methods' => $payments->sum('amount'),
            'total_discounts' => $payments->whereNotNull('discount_value')->sum('discount_value'),
        ]);
    }

    public function topItems(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date', now()->startOfWeek()->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());
        $limit = (int) $request->query('limit', '10');
        $branchId = $request->query('branch_id');

        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q
                ->where('status', 'paid')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($branchId, fn ($q2, $id) => $q2->where('branch_id', $id))
            )
            ->with('menuItem:id,name_ar,price,cost_price')
            ->selectRaw('menu_item_id, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue')
            ->groupBy('menu_item_id')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'name_ar' => $item->menuItem?->name_ar,
                'total_qty' => $item->total_qty,
                'total_revenue' => $item->total_revenue,
                'profit_margin' => $item->menuItem?->profitMargin(),
            ]);

        return ApiResponse::success($items);
    }
}
