<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Services;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Packages\Models\MenuPackageSlotOption;
use App\Modules\Tenant\Models\Tenant;
use InvalidArgumentException;

class PackageExpansionService
{
    /**
     * @param  array{
     *     package_id: int,
     *     quantity: int,
     *     notes?: string|null,
     *     selections?: list<array{slot_id: int, menu_item_ids: list<int>}>
     * }  $line
     */
    public function addToOrder(Order $order, array $line): OrderItem
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $tenant instanceof Tenant || ! $tenant->hasFeature('menu_packages')) {
            throw new InvalidArgumentException('Menu packages are not enabled for this restaurant.');
        }

        $package = MenuPackage::query()
            ->with(['slots.options.menuItem', 'slots.menuItem'])
            ->find($line['package_id']);

        if (! $package instanceof MenuPackage) {
            throw new InvalidArgumentException('Package not found.');
        }

        if (! $package->isSellable()) {
            throw new InvalidArgumentException("Package {$package->id} is unavailable.");
        }

        $quantity = max(1, (int) $line['quantity']);
        $resolved = $this->resolveSelections($package, $line['selections'] ?? []);
        $unitPrice = round((float) $package->price + $resolved['extra_price'], 2);

        $parent = $order->items()->create([
            'line_type' => OrderItem::LINE_PACKAGE,
            'menu_item_id' => null,
            'package_id' => $package->id,
            'parent_id' => null,
            'item_name_ar' => $package->name_ar,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => round($unitPrice * $quantity, 2),
            'status' => 'ready',
            'notes' => $line['notes'] ?? null,
            'selections' => $resolved['snapshot'],
        ]);

        foreach ($resolved['components'] as $component) {
            $order->items()->create([
                'line_type' => OrderItem::LINE_COMPONENT,
                'menu_item_id' => $component['menu_item']->id,
                'package_id' => $package->id,
                'parent_id' => $parent->id,
                'item_name_ar' => $component['menu_item']->name_ar,
                'unit_price' => 0,
                'quantity' => $component['quantity'] * $quantity,
                'subtotal' => 0,
                'status' => 'pending',
                'notes' => $component['notes'] ?? null,
            ]);
        }

        return $parent->fresh(['children']) ?? $parent;
    }

    /**
     * @param  list<array{slot_id: int, menu_item_ids: list<int>}>  $selections
     * @return array{
     *     extra_price: float,
     *     snapshot: list<array{slot_id: int, menu_item_ids: list<int>}>,
     *     components: list<array{menu_item: MenuItem, quantity: int, notes?: string|null}>
     * }
     */
    public function resolveSelections(MenuPackage $package, array $selections): array
    {
        $bySlot = [];
        foreach ($selections as $selection) {
            $bySlot[(int) $selection['slot_id']] = array_map('intval', $selection['menu_item_ids']);
        }

        $extraPrice = 0.0;
        $components = [];
        $snapshot = [];

        foreach ($package->slots as $slot) {
            if ($slot->isFixed()) {
                $item = $slot->menuItem;
                if (! $item instanceof MenuItem || ! $item->is_available) {
                    throw new InvalidArgumentException("Package slot {$slot->id} is unavailable.");
                }

                $components[] = [
                    'menu_item' => $item,
                    'quantity' => max(1, (int) $slot->quantity),
                ];

                continue;
            }

            $selectedIds = $bySlot[$slot->id] ?? [];
            $count = count($selectedIds);

            if ($count < (int) $slot->min_select || $count > (int) $slot->max_select) {
                throw new InvalidArgumentException(
                    "Slot {$slot->id} requires between {$slot->min_select} and {$slot->max_select} selections.",
                );
            }

            $options = $slot->options->keyBy('menu_item_id');

            foreach ($selectedIds as $menuItemId) {
                $option = $options->get($menuItemId);
                if (! $option instanceof MenuPackageSlotOption || ! $option->isSellable()) {
                    throw new InvalidArgumentException("Menu item {$menuItemId} is not a valid option for slot {$slot->id}.");
                }

                $extraPrice += (float) $option->extra_price;
                $components[] = [
                    'menu_item' => $option->menuItem,
                    'quantity' => max(1, (int) $slot->quantity),
                ];
            }

            $snapshot[] = [
                'slot_id' => $slot->id,
                'menu_item_ids' => $selectedIds,
            ];
        }

        return [
            'extra_price' => round($extraPrice, 2),
            'snapshot' => $snapshot,
            'components' => $components,
        ];
    }
}
