<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Services;

use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentRefund;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\BusinessDays\Models\BusinessDay;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Illuminate\Support\Collection;

class BusinessDayReportService
{
    /** @var array<string, int> */
    private const IN_TYPES = [
        'purchase' => 1,
        'transfer_in' => 1,
        'refund' => 1,
    ];

    /** @return array<string, mixed> */
    public function build(BusinessDay $day): array
    {
        $orders = $day->orders()->get();
        $payments = Payment::query()
            ->whereHas('order', fn ($query) => $query->where('business_day_id', $day->id))
            ->with('splits')
            ->get();
        $refunds = PaymentRefund::query()
            ->whereHas('order', fn ($query) => $query->where('business_day_id', $day->id))
            ->get();

        $items = OrderItem::query()
            ->whereNull('parent_id')
            ->whereHas('order', fn ($query) => $query
                ->where('business_day_id', $day->id)
                ->where('status', 'paid'))
            ->selectRaw('menu_item_id, package_id, item_name_ar, SUM(quantity) as quantity, SUM(subtotal) as total')
            ->groupBy('menu_item_id', 'package_id', 'item_name_ar')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn (OrderItem $item): array => [
                'menu_item_id' => $item->menu_item_id,
                'package_id' => $item->package_id,
                'name_ar' => $item->item_name_ar,
                'quantity' => (int) $item->getAttribute('quantity'),
                'total' => round((float) $item->getAttribute('total'), 2),
            ])
            ->values()
            ->all();

        $movements = $day->stockMovements()
            ->with('ingredient:id,name_ar,name_en,unit')
            ->get();

        $remaining = InventoryStock::query()
            ->where('branch_id', $day->branch_id)
            ->where('is_active', true)
            ->with('ingredient:id,name_ar,name_en,unit')
            ->get()
            ->map(fn (InventoryStock $stock): array => [
                'ingredient_id' => $stock->ingredient_id,
                'name_ar' => $stock->ingredient?->name_ar,
                'name_en' => $stock->ingredient?->name_en,
                'unit' => $stock->ingredient?->unit,
                'quantity' => (float) $stock->current_stock,
                'unit_cost' => (float) $stock->unit_cost,
                'value' => round((float) $stock->current_stock * (float) $stock->unit_cost, 2),
            ])
            ->values()
            ->all();

        $byMethod = [];
        foreach ($payments as $payment) {
            if ($payment->method === 'split') {
                foreach ($payment->splits as $split) {
                    $this->addPaymentMethod($byMethod, (string) $split->method, (float) $split->amount);
                }
            } else {
                $this->addPaymentMethod($byMethod, (string) $payment->method, (float) $payment->amount);
            }
        }

        $shifts = $day->shifts()
            ->with('user:id,name,role')
            ->orderBy('clock_in')
            ->get()
            ->map(fn (StaffShift $shift): array => [
                'id' => $shift->id,
                'user_id' => $shift->user_id,
                'user_name' => $shift->user?->name,
                'user_role' => $shift->user?->role,
                'clock_in' => $shift->clock_in->toIso8601String(),
                'clock_out' => $shift->clock_out?->toIso8601String(),
                'is_active' => $shift->isActive(),
                'opening_float' => (string) $shift->opening_float,
                'closing_cash_count' => $shift->closing_cash_count !== null ? (string) $shift->closing_cash_count : null,
                'expected_cash' => $shift->expected_cash !== null ? (string) $shift->expected_cash : null,
                'cash_variance' => $shift->cash_variance !== null ? (string) $shift->cash_variance : null,
            ])
            ->values()
            ->all();

        return [
            'business_day_id' => $day->id,
            'business_date' => $day->business_date->toDateString(),
            'branch_id' => $day->branch_id,
            'status' => $day->status,
            'opened_at' => $day->opened_at->toIso8601String(),
            'closed_at' => $day->closed_at?->toIso8601String(),
            'orders' => [
                'total' => $orders->count(),
                'paid' => $orders->where('status', 'paid')->count(),
                'cancelled' => $orders->where('status', 'cancelled')->count(),
                'refunded' => $orders->where('status', 'refunded')->count(),
                'gross_sales' => round((float) $payments->sum('amount'), 2),
                'discounts' => round((float) $orders->sum('discount'), 2),
                'tax' => round((float) $orders->where('status', 'paid')->sum('tax'), 2),
                'service_charge' => round((float) $orders->where('status', 'paid')->sum('service_charge'), 2),
                'refunds' => round((float) $refunds->sum('amount'), 2),
                'net_sales' => round((float) $payments->sum('amount') - (float) $refunds->sum('amount'), 2),
                'by_channel' => $orders->groupBy('channel')->map(fn ($group): array => [
                    'count' => $group->count(),
                    'paid_total' => round((float) $group->where('status', 'paid')->sum('total'), 2),
                ])->all(),
            ],
            'payments' => [
                'count' => $payments->count(),
                'by_method' => $byMethod,
                'total' => round((float) $payments->sum('amount'), 2),
            ],
            'items' => $items,
            'shifts' => [
                'count' => count($shifts),
                'active' => collect($shifts)->where('is_active', true)->count(),
                'list' => $shifts,
            ],
            'inventory' => [
                'opening' => $day->opening_stock ?? [],
                'remaining' => $remaining,
                'ledger' => $this->ingredientLedger($day->opening_stock ?? [], $remaining, $movements),
                'movements' => $movements
                    ->groupBy('type')
                    ->map(fn ($group): array => [
                        'quantity' => round((float) $group->sum('quantity'), 4),
                        'value' => round((float) $group->sum(
                            fn ($movement): float => (float) $movement->quantity * (float) $movement->unit_cost,
                        ), 2),
                    ])
                    ->all(),
                'waste' => $movements
                    ->where('type', 'waste')
                    ->map(fn ($movement): array => [
                        'id' => $movement->id,
                        'ingredient_id' => $movement->ingredient_id,
                        'name_ar' => $movement->ingredient?->name_ar,
                        'quantity' => (float) $movement->quantity,
                        'unit' => $movement->ingredient?->unit,
                        'notes' => $movement->notes,
                        'created_at' => $movement->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'returned' => $movements
                    ->where('type', 'transfer_out')
                    ->map(fn ($movement): array => [
                        'id' => $movement->id,
                        'ingredient_id' => $movement->ingredient_id,
                        'name_ar' => $movement->ingredient?->name_ar,
                        'quantity' => (float) $movement->quantity,
                        'unit' => $movement->ingredient?->unit,
                        'notes' => $movement->notes,
                        'created_at' => $movement->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $opening
     * @param  list<array<string, mixed>>  $remaining
     * @param  Collection<int, StockMovement>  $movements
     * @return list<array<string, mixed>>
     */
    private function ingredientLedger(array $opening, array $remaining, Collection $movements): array
    {
        $rows = [];

        foreach ($opening as $row) {
            $id = (int) $row['ingredient_id'];
            $rows[$id] = $this->emptyLedgerRow($row, (float) $row['quantity']);
        }

        foreach ($remaining as $row) {
            $id = (int) $row['ingredient_id'];
            $rows[$id] ??= $this->emptyLedgerRow($row, 0.0);
            $rows[$id]['remaining'] = (float) $row['quantity'];
            $rows[$id]['unit_cost'] = (float) ($row['unit_cost'] ?? $rows[$id]['unit_cost']);
            $rows[$id]['name_ar'] = $row['name_ar'] ?? $rows[$id]['name_ar'];
            $rows[$id]['name_en'] = $row['name_en'] ?? $rows[$id]['name_en'];
            $rows[$id]['unit'] = $row['unit'] ?? $rows[$id]['unit'];
        }

        foreach ($movements as $movement) {
            $id = (int) $movement->ingredient_id;
            $rows[$id] ??= $this->emptyLedgerRow([
                'ingredient_id' => $id,
                'name_ar' => $movement->ingredient?->name_ar,
                'name_en' => $movement->ingredient?->name_en,
                'unit' => $movement->ingredient?->unit,
                'unit_cost' => $movement->unit_cost,
            ], 0.0);

            $quantity = (float) $movement->quantity;
            $type = (string) $movement->type;

            if (isset(self::IN_TYPES[$type])) {
                $rows[$id]['added'] = round($rows[$id]['added'] + $quantity, 4);
            }

            if ($type === 'sale') {
                $rows[$id]['consumed'] = round($rows[$id]['consumed'] + $quantity, 4);
            }

            if ($type === 'waste') {
                $rows[$id]['waste'] = round($rows[$id]['waste'] + $quantity, 4);
            }

            if ($type === 'transfer_out') {
                $rows[$id]['returned'] = round($rows[$id]['returned'] + $quantity, 4);
            }
        }

        return collect($rows)
            ->map(function (array $row): array {
                $row['expected'] = round(
                    $row['opening'] + $row['added'] - $row['consumed'] - $row['waste'] - $row['returned'],
                    4,
                );
                $row['variance'] = round($row['remaining'] - $row['expected'], 4);

                return $row;
            })
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $source */
    private function emptyLedgerRow(array $source, float $opening): array
    {
        return [
            'ingredient_id' => $source['ingredient_id'] ?? null,
            'name_ar' => $source['name_ar'] ?? null,
            'name_en' => $source['name_en'] ?? null,
            'unit' => $source['unit'] ?? null,
            'unit_cost' => (float) ($source['unit_cost'] ?? 0),
            'opening' => $opening,
            'added' => 0.0,
            'consumed' => 0.0,
            'waste' => 0.0,
            'returned' => 0.0,
            'expected' => $opening,
            'remaining' => 0.0,
            'variance' => 0.0,
        ];
    }

    /** @param array<string, array{count: int, total: float}> $totals */
    private function addPaymentMethod(array &$totals, string $method, float $amount): void
    {
        $totals[$method] ??= ['count' => 0, 'total' => 0.0];
        $totals[$method]['count']++;
        $totals[$method]['total'] = round($totals[$method]['total'] + $amount, 2);
    }
}
