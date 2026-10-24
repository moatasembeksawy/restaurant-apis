<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Services;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Models\MenuItemPart;
use App\Modules\POS\Menu\Models\MenuItemPartOption;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ItemPartExpansionService
{
    /**
     * @param  array<string, mixed>  $line
     */
    public function addToOrder(Order $order, MenuItem $menuItem, array $line): OrderItem
    {
        $menuItem->loadMissing([
            'parts.options.menuItem',
            'parts.options.ingredient',
            'parts.defaultMenuItem',
            'parts.ingredient',
        ]);

        $hasSoldPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null;

        if (! $menuItem->isSellable() && ! $hasSoldPrice) {
            throw new InvalidArgumentException(__('Menu item :menu_item_id is unavailable.', ['menu_item_id' => $menuItem->id]));
        }

        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $resolved = $this->resolve($menuItem, $this->incomingSelections($line));
        $catalogPrice = round((float) $menuItem->price + $resolved['extra_price'], 2);
        $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null
            ? round((float) $line['unit_price'], 2)
            : $catalogPrice;

        if ($unitPrice < 0) {
            throw new InvalidArgumentException('Item price cannot be negative.');
        }

        $notes = $line['notes'] ?? null;
        if ($resolved['notes'] !== null) {
            $notes = trim(implode("\n", array_filter([(string) $notes, $resolved['notes']], fn (string $value): bool => $value !== '')));
        }

        $parent = $order->items()->create([
            'line_type' => OrderItem::LINE_ITEM,
            'menu_item_id' => $menuItem->id,
            'package_id' => null,
            'parent_id' => null,
            'item_name_ar' => $menuItem->name_ar,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => round($unitPrice * $quantity, 2),
            'status' => 'pending',
            'notes' => $notes,
            'selections' => $resolved['snapshot'],
            'client_item_id' => $line['client_item_id'] ?? null,
        ]);

        foreach ($resolved['components'] as $component) {
            $order->items()->create([
                'line_type' => OrderItem::LINE_ITEM_COMPONENT,
                'menu_item_id' => $component['menu_item']->id,
                'package_id' => null,
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
     *     snapshot: list<array<string, mixed>>,
     *     notes: string|null,
     *     components: list<array{menu_item: MenuItem, quantity: int, notes?: string|null}>
     * }
     */
    public function resolve(MenuItem $menuItem, array $selections): array
    {
        $indexed = $this->indexSelections($menuItem, $selections);
        $extraPrice = 0.0;
        $components = [];
        $snapshot = [];
        $notes = [];

        foreach ($menuItem->parts as $part) {
            if ($part->isFixed()) {
                $item = $part->defaultMenuItem;
                if (! $item instanceof MenuItem || ! $item->is_available) {
                    throw new InvalidArgumentException(__('Item part :part_id is unavailable.', ['part_id' => $part->id]));
                }

                $components[] = [
                    'menu_item' => $item,
                    'quantity' => max(1, (int) $part->quantity),
                ];

                continue;
            }

            if ($part->isWeight()) {
                $resolved = $this->resolveWeight($part, $indexed[$part->id] ?? []);
                $extraPrice += $resolved['extra_price'];
                $snapshot[] = $resolved['snapshot'];
                $notes[] = $resolved['snapshot']['label'];

                continue;
            }

            $selectedIds = $indexed[$part->id]['menu_item_ids'] ?? [];
            $resolved = $part->isSwap()
                ? $this->resolveSwap($part, $selectedIds)
                : $this->resolveChoice($part, $selectedIds);

            $extraPrice += $resolved['extra_price'];
            foreach ($resolved['components'] as $component) {
                $components[] = $component;
            }
            $snapshot[] = $resolved['snapshot'];
        }

        $notes = array_values(array_filter($notes, fn (mixed $note): bool => is_string($note) && $note !== ''));

        return [
            'extra_price' => round($extraPrice, 2),
            'snapshot' => $snapshot,
            'notes' => $notes === [] ? null : implode("\n", $notes),
            'components' => $components,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<int|string, mixed>
     */
    private function incomingSelections(array $line): array
    {
        $selections = $line['selections'] ?? $line['parts'] ?? [];

        return is_array($selections) ? $selections : [];
    }

    /**
     * @param  array<int|string, mixed>  $selections
     * @return array<int, array{menu_item_ids: list<int>, ingredient_id: int|null, quantity: float|null}>
     */
    private function indexSelections(MenuItem $menuItem, array $selections): array
    {
        /** @var Collection<int, MenuItemPart> $parts */
        $parts = $menuItem->parts->values();
        $indexed = [];

        foreach ($selections as $key => $selection) {
            $partId = $this->partIdFrom($selection, $key);
            $itemIds = $this->menuItemIdsFrom($selection);
            $ingredientId = $this->ingredientIdFrom($selection);
            $quantity = $this->quantityFrom($selection);
            $part = $partId > 0 ? $parts->firstWhere('id', $partId) : null;

            if (! $part instanceof MenuItemPart) {
                $part = $this->partForSelection($parts, $itemIds, $ingredientId);
            }

            if (! $part instanceof MenuItemPart) {
                continue;
            }

            $current = $indexed[$part->id] ?? [
                'menu_item_ids' => [],
                'ingredient_id' => null,
                'quantity' => null,
            ];

            $indexed[$part->id] = [
                'menu_item_ids' => array_values(array_unique([...$current['menu_item_ids'], ...$itemIds])),
                'ingredient_id' => $ingredientId ?? $current['ingredient_id'],
                'quantity' => $quantity ?? $current['quantity'],
            ];
        }

        return $indexed;
    }

    /**
     * @param  Collection<int, MenuItemPart>  $parts
     * @param  list<int>  $itemIds
     */
    private function partForSelection(Collection $parts, array $itemIds, ?int $ingredientId): ?MenuItemPart
    {
        foreach ($parts as $part) {
            if ($ingredientId !== null && $part->isWeight()) {
                if ((int) $part->ingredient_id === $ingredientId) {
                    return $part;
                }

                if ($part->options->contains(
                    fn (MenuItemPartOption $option): bool => (int) $option->ingredient_id === $ingredientId,
                )) {
                    return $part;
                }
            }

            foreach ($itemIds as $itemId) {
                if ($part->isSwap() && (int) $part->default_menu_item_id === $itemId) {
                    return $part;
                }

                if ($part->options->contains(
                    fn (MenuItemPartOption $option): bool => (int) $option->menu_item_id === $itemId,
                )) {
                    return $part;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $selectedIds
     * @return array{extra_price: float, snapshot: array<string, mixed>, components: list<array{menu_item: MenuItem, quantity: int}>}
     */
    private function resolveChoice(MenuItemPart $part, array $selectedIds): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        $options = $part->options->keyBy('menu_item_id');
        $available = $part->options
            ->filter(fn (MenuItemPartOption $option): bool => $option->isSellable())
            ->values();

        if ($selectedIds === []) {
            $min = (int) $part->min_select;
            if ($available->count() === $min && $min <= (int) $part->max_select) {
                $selectedIds = $available
                    ->map(fn (MenuItemPartOption $option): int => (int) $option->menu_item_id)
                    ->all();
            }
        }

        $count = count($selectedIds);
        if ($count < (int) $part->min_select || $count > (int) $part->max_select) {
            throw new InvalidArgumentException(
                __('Part :part_id requires between :min and :max selections.', [
                    'part_id' => $part->id,
                    'min' => $part->min_select,
                    'max' => $part->max_select,
                ]),
            );
        }

        $extraPrice = 0.0;
        $components = [];

        foreach ($selectedIds as $menuItemId) {
            $option = $options->get($menuItemId);
            if (! $option instanceof MenuItemPartOption || ! $option->isSellable() || ! $option->menuItem instanceof MenuItem) {
                throw new InvalidArgumentException(__('Menu item :menu_item_id is not a valid option for part :part_id.', [
                    'menu_item_id' => $menuItemId,
                    'part_id' => $part->id,
                ]));
            }

            $extraPrice += (float) $option->extra_price;
            $components[] = [
                'menu_item' => $option->menuItem,
                'quantity' => max(1, (int) $part->quantity),
            ];
        }

        return [
            'extra_price' => $extraPrice,
            'snapshot' => [
                'part_id' => $part->id,
                'type' => $part->type,
                'menu_item_ids' => $selectedIds,
            ],
            'components' => $components,
        ];
    }

    /**
     * @param  list<int>  $selectedIds
     * @return array{extra_price: float, snapshot: array<string, mixed>, components: list<array{menu_item: MenuItem, quantity: int}>}
     */
    private function resolveSwap(MenuItemPart $part, array $selectedIds): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        $default = $part->defaultMenuItem;
        $defaultAvailable = $default instanceof MenuItem && $default->is_available;
        $options = $part->options->keyBy('menu_item_id');
        $available = $part->options
            ->filter(fn (MenuItemPartOption $option): bool => $option->isSellable())
            ->values();

        if ($selectedIds === []) {
            if ($defaultAvailable) {
                return [
                    'extra_price' => 0.0,
                    'snapshot' => [
                        'part_id' => $part->id,
                        'type' => $part->type,
                        'menu_item_ids' => [(int) $part->default_menu_item_id],
                    ],
                    'components' => [[
                        'menu_item' => $default,
                        'quantity' => max(1, (int) $part->quantity),
                    ]],
                ];
            }

            if ($available->count() === 1) {
                $only = $available->first();
                if ($only instanceof MenuItemPartOption) {
                    $selectedIds = [(int) $only->menu_item_id];
                }
            }
        }

        if (count($selectedIds) !== 1) {
            throw new InvalidArgumentException(__('Part :part_id requires one selection.', ['part_id' => $part->id]));
        }

        $chosenId = $selectedIds[0];

        if ($chosenId === (int) $part->default_menu_item_id) {
            if (! $defaultAvailable) {
                throw new InvalidArgumentException(__('Item part :part_id is unavailable.', ['part_id' => $part->id]));
            }

            return [
                'extra_price' => 0.0,
                'snapshot' => [
                    'part_id' => $part->id,
                    'type' => $part->type,
                    'menu_item_ids' => [$chosenId],
                ],
                'components' => [[
                    'menu_item' => $default,
                    'quantity' => max(1, (int) $part->quantity),
                ]],
            ];
        }

        $option = $options->get($chosenId);
        if (! $option instanceof MenuItemPartOption || ! $option->isSellable() || ! $option->menuItem instanceof MenuItem) {
            throw new InvalidArgumentException(__('Menu item :menu_item_id is not a valid option for part :part_id.', [
                'menu_item_id' => $chosenId,
                'part_id' => $part->id,
            ]));
        }

        return [
            'extra_price' => (float) $option->extra_price,
            'snapshot' => [
                'part_id' => $part->id,
                'type' => $part->type,
                'menu_item_ids' => [$chosenId],
            ],
            'components' => [[
                'menu_item' => $option->menuItem,
                'quantity' => max(1, (int) $part->quantity),
            ]],
        ];
    }

    /**
     * @param  array{menu_item_ids?: list<int>, ingredient_id?: int|null, quantity?: float|null}  $selection
     * @return array{extra_price: float, snapshot: array<string, mixed>}
     */
    private function resolveWeight(MenuItemPart $part, array $selection): array
    {
        $default = $part->ingredient;
        if (! $default instanceof Ingredient) {
            throw new InvalidArgumentException(__('Item part :part_id is unavailable.', ['part_id' => $part->id]));
        }

        $ingredientId = $selection['ingredient_id'] ?? (int) $part->ingredient_id;
        $quantity = $selection['quantity'] ?? (float) $part->included_quantity;
        $quantity = round((float) $quantity, 4);

        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('Part :part_id requires a weight greater than zero.', ['part_id' => $part->id]));
        }

        $rate = (float) $part->price_per_unit;

        if ($ingredientId !== (int) $part->ingredient_id) {
            $option = $part->options->first(
                fn (MenuItemPartOption $row): bool => (int) $row->ingredient_id === $ingredientId,
            );

            if (! $option instanceof MenuItemPartOption || ! $option->isSellable() || ! $option->ingredient instanceof Ingredient) {
                throw new InvalidArgumentException(__('Ingredient :ingredient_id is not a valid option for part :part_id.', [
                    'ingredient_id' => $ingredientId,
                    'part_id' => $part->id,
                ]));
            }

            $ingredient = $option->ingredient;
            $rate = (float) $option->price_per_unit;
        } else {
            if ($default->is_active !== true) {
                throw new InvalidArgumentException(__('Item part :part_id is unavailable.', ['part_id' => $part->id]));
            }

            $ingredient = $default;
        }

        $includedCost = (float) $part->included_quantity * (float) $part->price_per_unit;
        $actualCost = $quantity * $rate;
        $label = trim($ingredient->name_ar.' '.$this->formatWeight($quantity).' '.$part->unit);

        return [
            'extra_price' => round($actualCost - $includedCost, 2),
            'snapshot' => [
                'part_id' => $part->id,
                'type' => $part->type,
                'ingredient_id' => $ingredient->id,
                'quantity' => $quantity,
                'unit' => $part->unit,
                'price_per_unit' => $rate,
                'label' => $label,
            ],
        ];
    }

    private function formatWeight(float $quantity): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function partIdFrom(mixed $selection, int|string $key): int
    {
        if (! is_array($selection)) {
            return is_numeric($key) ? (int) $key : 0;
        }

        foreach (['part_id', 'id'] as $field) {
            if (isset($selection[$field]) && is_numeric($selection[$field]) && (int) $selection[$field] > 0) {
                return (int) $selection[$field];
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

        $ids = [];

        foreach (['menu_item_ids', 'selected_menu_item_ids'] as $key) {
            if (array_key_exists($key, $selection) && is_array($selection[$key])) {
                foreach ($selection[$key] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        foreach (['menu_item_id', 'selected_menu_item_id'] as $key) {
            if (isset($selection[$key]) && is_numeric($selection[$key]) && (int) $selection[$key] > 0) {
                $ids[] = (int) $selection[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    private function ingredientIdFrom(mixed $selection): ?int
    {
        if (! is_array($selection)) {
            return null;
        }

        if (isset($selection['ingredient_id']) && is_numeric($selection['ingredient_id']) && (int) $selection['ingredient_id'] > 0) {
            return (int) $selection['ingredient_id'];
        }

        return null;
    }

    private function quantityFrom(mixed $selection): ?float
    {
        if (! is_array($selection)) {
            return null;
        }

        foreach (['quantity', 'weight', 'grams'] as $key) {
            if (isset($selection[$key]) && is_numeric($selection[$key])) {
                return (float) $selection[$key];
            }
        }

        return null;
    }
}
