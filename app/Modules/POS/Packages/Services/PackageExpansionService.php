<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Services;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Packages\Models\MenuPackageSlot;
use App\Modules\POS\Packages\Models\MenuPackageSlotOption;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PackageExpansionService
{
    /**
     * @param  array<string, mixed>  $line
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

        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $resolved = $this->resolveSelections($package, $this->incomingSelections($line));
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
     * @param  array<int|string, mixed>  $selections
     * @return array{
     *     extra_price: float,
     *     snapshot: list<array{slot_id: int, menu_item_ids: list<int>}>,
     *     components: list<array{menu_item: MenuItem, quantity: int, notes?: string|null}>
     * }
     */
    public function resolveSelections(MenuPackage $package, array $selections): array
    {
        $bySlot = $this->indexSelections($package, $selections);

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
            $min = (int) $slot->min_select;
            $max = (int) $slot->max_select;

            if ($count < $min || $count > $max) {
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

    /**
     * @param  array<string, mixed>  $line
     * @return array<int|string, mixed>
     */
    private function incomingSelections(array $line): array
    {
        $selections = $line['selections'] ?? $line['slots'] ?? [];
        if (! is_array($selections)) {
            $selections = [];
        }

        foreach ($this->numericIds($line['menu_item_ids'] ?? null) as $id) {
            $selections[] = $id;
        }

        if (isset($line['menu_item_id']) && is_numeric($line['menu_item_id'])) {
            $selections[] = (int) $line['menu_item_id'];
        }

        return $selections;
    }

    /**
     * @param  array<int|string, mixed>  $selections
     * @return array<int, list<int>>
     */
    private function indexSelections(MenuPackage $package, array $selections): array
    {
        /** @var Collection<int, MenuPackageSlot> $choiceSlots */
        $choiceSlots = $package->slots
            ->filter(fn (MenuPackageSlot $slot): bool => ! $slot->isFixed())
            ->values();

        $bySlot = [];
        $unassigned = [];
        $isList = array_is_list($selections);

        foreach ($selections as $key => $selection) {
            $slotId = $this->slotIdFrom($selection);
            if ($slotId < 1 && ! $isList && is_numeric($key) && (int) $key > 0) {
                $slotId = (int) $key;
            }

            $itemIds = $this->menuItemIdsFrom($selection);
            $matchedSlot = $slotId > 0 ? $choiceSlots->firstWhere('id', $slotId) : null;

            if (! $matchedSlot instanceof MenuPackageSlot && $slotId > 0) {
                $matchedSlot = $this->slotForOptionId($choiceSlots, $slotId);
                if ($matchedSlot instanceof MenuPackageSlot && $itemIds === []) {
                    $option = $matchedSlot->options->firstWhere('id', $slotId);
                    if ($option instanceof MenuPackageSlotOption) {
                        $itemIds = [(int) $option->menu_item_id];
                    }
                }
            }

            if ($matchedSlot instanceof MenuPackageSlot) {
                $bySlot[$matchedSlot->id] = array_merge($bySlot[$matchedSlot->id] ?? [], $itemIds);

                continue;
            }

            if ($slotId > 0 && $itemIds === []) {
                $unassigned[] = $slotId;
            }

            foreach ($itemIds as $itemId) {
                $unassigned[] = $itemId;
            }
        }

        foreach ($unassigned as $candidateId) {
            $this->assignItemToSlot($bySlot, $choiceSlots, $candidateId);
        }

        foreach ($choiceSlots as $slot) {
            $bySlot[$slot->id] = array_values(array_unique(array_map('intval', $bySlot[$slot->id] ?? [])));

            if ($bySlot[$slot->id] !== []) {
                continue;
            }

            $available = $slot->options
                ->filter(fn (MenuPackageSlotOption $option): bool => $option->isSellable())
                ->values();

            $min = (int) $slot->min_select;
            if ($available->count() === $min && $min <= (int) $slot->max_select) {
                $bySlot[$slot->id] = $available
                    ->map(fn (MenuPackageSlotOption $option): int => (int) $option->menu_item_id)
                    ->all();
            }
        }

        return $bySlot;
    }

    /**
     * @param  array<int, list<int>>  $bySlot
     * @param  Collection<int, MenuPackageSlot>  $choiceSlots
     */
    private function assignItemToSlot(array &$bySlot, Collection $choiceSlots, int $candidateId): bool
    {
        foreach ($choiceSlots as $slot) {
            $option = $slot->options->first(
                fn (MenuPackageSlotOption $row): bool => (int) $row->menu_item_id === $candidateId
                    || (int) $row->id === $candidateId,
            );

            if (! $option instanceof MenuPackageSlotOption) {
                continue;
            }

            $current = $bySlot[$slot->id] ?? [];
            if (in_array((int) $option->menu_item_id, $current, true)) {
                return true;
            }

            if (count($current) >= (int) $slot->max_select) {
                continue;
            }

            $bySlot[$slot->id] = [...$current, (int) $option->menu_item_id];

            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, MenuPackageSlot>  $choiceSlots
     */
    private function slotForOptionId(Collection $choiceSlots, int $optionId): ?MenuPackageSlot
    {
        return $choiceSlots->first(
            fn (MenuPackageSlot $slot): bool => $slot->options->contains(
                fn (MenuPackageSlotOption $option): bool => (int) $option->id === $optionId,
            ),
        );
    }

    private function slotIdFrom(mixed $selection): int
    {
        if (! is_array($selection)) {
            return 0;
        }

        foreach (['slot_id', 'id'] as $key) {
            if (isset($selection[$key]) && is_numeric($selection[$key]) && (int) $selection[$key] > 0) {
                return (int) $selection[$key];
            }
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    private function menuItemIdsFrom(mixed $selection): array
    {
        if (is_numeric($selection)) {
            return [(int) $selection];
        }

        if (! is_array($selection)) {
            return [];
        }

        if ($selection !== [] && array_is_list($selection) && is_numeric($selection[0] ?? null)) {
            return $this->numericIds($selection);
        }

        $ids = [];

        foreach (['menu_item_ids', 'selected_menu_item_ids', 'selected_ids'] as $key) {
            if (array_key_exists($key, $selection)) {
                $ids = [...$ids, ...$this->numericIds($selection[$key])];
            }
        }

        foreach (['menu_item_id', 'selected_menu_item_id', 'option_id'] as $key) {
            if (isset($selection[$key]) && is_numeric($selection[$key])) {
                $ids[] = (int) $selection[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function numericIds(mixed $value): array
    {
        if (is_numeric($value)) {
            return [(int) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
