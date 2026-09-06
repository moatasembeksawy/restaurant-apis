<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Services;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Print\Models\KitchenStation;
use App\Modules\POS\Print\Models\Printer;
use App\Modules\POS\Print\Models\PrintRoute;
use App\Shared\Infrastructure\PrintJob\EscPosBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class KitchenPrintRoutingService
{
    public function __construct(
        private readonly EscPosBuilder $escPos,
    ) {}

    /** @return list<array<string, mixed>> */
    public function jobsFor(Order $order): array
    {
        $order->load(['items.menuItem.category', 'table', 'branch']);
        $branch = $order->branch;

        if (! $branch) {
            throw new InvalidArgumentException('Branch not found for this order.');
        }

        $menuItemIds = $order->items->pluck('menu_item_id')->filter()->unique()->values();
        $categoryIds = $order->items
            ->pluck('menuItem.category_id')
            ->filter()
            ->unique()
            ->values();

        $routes = PrintRoute::query()
            ->where('branch_id', $branch->id)
            ->where(function ($query) use ($menuItemIds, $categoryIds): void {
                $query->whereIn('menu_item_id', $menuItemIds)
                    ->orWhereIn('menu_category_id', $categoryIds);
            })
            ->with(['printer', 'station.printers'])
            ->get();

        $defaultPrinters = Printer::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereIn('type', ['kitchen', 'both'])
            ->orderByDesc('is_default_kitchen')
            ->orderBy('id')
            ->get();

        if (! $defaultPrinters->contains('is_default_kitchen', true)) {
            $defaultPrinters = $defaultPrinters->take(1);
        } else {
            $defaultPrinters = $defaultPrinters->where('is_default_kitchen', true);
        }

        /** @var array<int, array{printer: Printer, stations: array<string, string>, items: array<int, OrderItem>}> $groups */
        $groups = [];

        foreach ($order->items as $orderItem) {
            $itemRoutes = $routes->where('menu_item_id', $orderItem->menu_item_id);
            if ($itemRoutes->isEmpty()) {
                $itemRoutes = $routes->where('menu_category_id', $orderItem->menuItem?->category_id);
            }

            $targets = $this->printersForRoutes($itemRoutes);
            if ($targets === []) {
                $targets = $defaultPrinters
                    ->map(fn (Printer $printer): array => ['printer' => $printer, 'station' => null])
                    ->all();
            }

            foreach ($targets as $target) {
                $printer = $target['printer'];
                $groups[$printer->id] ??= [
                    'printer' => $printer,
                    'stations' => [],
                    'items' => [],
                ];
                $groups[$printer->id]['items'][$orderItem->id] = $orderItem;

                if ($target['station']) {
                    $groups[$printer->id]['stations'][(string) $target['station']->id] = $target['station']->name;
                }
            }
        }

        if ($groups === []) {
            throw new InvalidArgumentException('No active kitchen printer is configured for this branch.');
        }

        return collect($groups)
            ->map(function (array $group) use ($order, $branch): array {
                $printer = $group['printer'];
                $items = collect(array_values($group['items']));
                $stationNames = array_values($group['stations']);
                $ticketOrder = clone $order;
                $ticketOrder->setRelation('items', $items);

                return [
                    'printer_id' => $printer->id,
                    'printer_name' => $printer->name,
                    'bridge_key' => $printer->bridge_key,
                    'paper_width' => $printer->paper_width,
                    'copies' => $printer->copies,
                    'auto_print' => $printer->auto_print,
                    'station_names' => $stationNames,
                    'format' => 'escpos',
                    'encoding' => 'binary',
                    'items' => $items->map(fn (OrderItem $item): array => [
                        'order_item_id' => $item->id,
                        'menu_item_id' => $item->menu_item_id,
                        'name' => $item->item_name_ar,
                        'quantity' => $item->quantity,
                        'notes' => $item->notes,
                    ])->values()->all(),
                    'bytes' => base64_encode($this->escPos->buildKitchenTicket(
                        $ticketOrder,
                        $branch,
                        $stationNames !== [] ? implode(' / ', $stationNames) : null,
                    )),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PrintRoute>  $routes
     * @return list<array{printer: Printer, station: KitchenStation|null}>
     */
    private function printersForRoutes(Collection $routes): array
    {
        $targets = [];

        foreach ($routes as $route) {
            if ($route->printer?->is_active && in_array($route->printer->type, ['kitchen', 'both'], true)) {
                $targets[$route->printer->id] = [
                    'printer' => $route->printer,
                    'station' => null,
                ];
            }

            if ($route->station?->is_active) {
                foreach ($route->station->printers as $printer) {
                    if ($printer->is_active && in_array($printer->type, ['kitchen', 'both'], true)) {
                        $targets[$printer->id] = [
                            'printer' => $printer,
                            'station' => $route->station,
                        ];
                    }
                }
            }
        }

        return array_values($targets);
    }
}
