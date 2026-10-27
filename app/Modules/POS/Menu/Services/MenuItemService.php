<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Services;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Models\MenuItemPart;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MenuItemService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MenuItem
    {
        return DB::transaction(function () use ($data): MenuItem {
            /** @var list<array<string, mixed>> $parts */
            $parts = $data['parts'] ?? [];
            unset($data['parts']);

            $item = MenuItem::create($data);
            $this->syncParts($item, $parts);

            return $item->fresh($this->relations()) ?? $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MenuItem $item, array $data): MenuItem
    {
        return DB::transaction(function () use ($item, $data): MenuItem {
            /** @var list<array<string, mixed>>|null $parts */
            $parts = array_key_exists('parts', $data) ? $data['parts'] : null;
            unset($data['parts']);

            $item->update($data);

            if (is_array($parts)) {
                $existing = $item->parts()->with('options')->get();
                $parts = $this->fillEchoedPartDefaults($item->id, $parts, $existing);

                foreach ($existing as $part) {
                    $part->options()->delete();
                    $part->delete();
                }
                $this->syncParts($item, $parts);
            }

            return $item->fresh($this->relations()) ?? $item;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    public function syncParts(MenuItem $item, array $parts): void
    {
        foreach ($parts as $index => $partData) {
            $type = (string) ($partData['type'] ?? '');

            if (! in_array($type, MenuItemPart::TYPES, true)) {
                throw new InvalidArgumentException('Invalid menu item part type.');
            }

            match ($type) {
                MenuItemPart::TYPE_FIXED => $this->createFixedPart($item, $partData, $index),
                MenuItemPart::TYPE_CHOICE => $this->createChoicePart($item, $partData, $index),
                MenuItemPart::TYPE_SWAP => $this->createSwapPart($item, $partData, $index),
                MenuItemPart::TYPE_WEIGHT => $this->createWeightPart($item, $partData, $index),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $partData
     */
    private function createFixedPart(MenuItem $item, array $partData, int $index): void
    {
        $menuItemId = $this->defaultMenuItemId($partData, $item->id) ?? 0;
        $this->assertMenuItem($menuItemId, $item->id);

        $item->parts()->create([
            'type' => MenuItemPart::TYPE_FIXED,
            'default_menu_item_id' => $menuItemId,
            'quantity' => max(1, (int) ($partData['quantity'] ?? 1)),
            'name_ar' => $partData['name_ar'] ?? null,
            'name_en' => $partData['name_en'] ?? null,
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => (int) ($partData['sort_order'] ?? $index),
        ]);
    }

    /**
     * @param  array<string, mixed>  $partData
     */
    private function createChoicePart(MenuItem $item, array $partData, int $index): void
    {
        /** @var list<array<string, mixed>> $options */
        $options = $partData['options'] ?? [];

        if ($options === []) {
            throw new InvalidArgumentException('Choice parts require at least one menu item option.');
        }

        $minSelect = (int) ($partData['min_select'] ?? 1);
        $maxSelect = (int) ($partData['max_select'] ?? $minSelect);

        if ($minSelect < 1 || $maxSelect < $minSelect) {
            throw new InvalidArgumentException('Choice part min/max select is invalid.');
        }

        if (count($options) < $minSelect) {
            throw new InvalidArgumentException('Choice part needs at least as many options as min_select.');
        }

        $part = $item->parts()->create([
            'type' => MenuItemPart::TYPE_CHOICE,
            'quantity' => max(1, (int) ($partData['quantity'] ?? 1)),
            'name_ar' => $partData['name_ar'] ?? 'اختيار',
            'name_en' => $partData['name_en'] ?? null,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'sort_order' => (int) ($partData['sort_order'] ?? $index),
        ]);

        $this->syncMenuItemOptions($item, $part, $options);
    }

    /**
     * @param  array<string, mixed>  $partData
     */
    private function createSwapPart(MenuItem $item, array $partData, int $index): void
    {
        $menuItemId = $this->defaultMenuItemId($partData, $item->id);

        if ($menuItemId !== null) {
            $this->assertMenuItem($menuItemId, $item->id);
        }

        /** @var list<array<string, mixed>> $options */
        $options = $partData['options'] ?? [];

        if ($options === []) {
            throw new InvalidArgumentException('Swap parts require at least one alternative menu item.');
        }

        $part = $item->parts()->create([
            'type' => MenuItemPart::TYPE_SWAP,
            'default_menu_item_id' => $menuItemId,
            'quantity' => max(1, (int) ($partData['quantity'] ?? 1)),
            'name_ar' => $partData['name_ar'] ?? 'تبديل',
            'name_en' => $partData['name_en'] ?? null,
            'min_select' => 0,
            'max_select' => 1,
            'sort_order' => (int) ($partData['sort_order'] ?? $index),
        ]);

        $this->syncMenuItemOptions($item, $part, $options);
    }

    /**
     * @param  array<string, mixed>  $partData
     */
    private function createWeightPart(MenuItem $item, array $partData, int $index): void
    {
        $ingredientId = (int) ($partData['ingredient_id'] ?? 0);
        $ingredient = $this->assertWeightIngredient($ingredientId);
        $unit = Ingredient::canonicalizeUnit((string) ($partData['unit'] ?? 'g'));

        if (! in_array($unit, MenuItemPart::WEIGHT_UNITS, true)) {
            throw new InvalidArgumentException('Weight parts must use g or kg.');
        }

        $included = round((float) ($partData['included_quantity'] ?? 0), 4);
        $pricePerUnit = round((float) ($partData['price_per_unit'] ?? 0), 4);

        if ($included <= 0) {
            throw new InvalidArgumentException('Weight parts require an included quantity greater than zero.');
        }

        if ($pricePerUnit < 0) {
            throw new InvalidArgumentException('Weight part price per unit cannot be negative.');
        }

        $includedCost = round($included * $pricePerUnit, 2);
        if ($includedCost > (float) $item->price) {
            throw new InvalidArgumentException('Included weight cost cannot exceed the menu item price.');
        }

        $this->assertCompatibleUnits($unit, (string) $ingredient->unit);

        $part = $item->parts()->create([
            'type' => MenuItemPart::TYPE_WEIGHT,
            'ingredient_id' => $ingredient->id,
            'included_quantity' => $included,
            'unit' => $unit,
            'price_per_unit' => $pricePerUnit,
            'quantity' => 1,
            'name_ar' => $partData['name_ar'] ?? 'الوزن',
            'name_en' => $partData['name_en'] ?? null,
            'min_select' => 0,
            'max_select' => 1,
            'sort_order' => (int) ($partData['sort_order'] ?? $index),
        ]);

        /** @var list<array<string, mixed>> $options */
        $options = $partData['options'] ?? [];

        foreach ($options as $optionIndex => $option) {
            $optionIngredientId = (int) ($option['ingredient_id'] ?? 0);
            $optionIngredient = $this->assertWeightIngredient($optionIngredientId);
            $optionRate = round((float) ($option['price_per_unit'] ?? $pricePerUnit), 4);

            if ($optionRate < 0) {
                throw new InvalidArgumentException('Weight option price per unit cannot be negative.');
            }

            $this->assertCompatibleUnits($unit, (string) $optionIngredient->unit);

            $part->options()->create([
                'ingredient_id' => $optionIngredient->id,
                'price_per_unit' => $optionRate,
                'extra_price' => 0,
                'is_available' => (bool) ($option['is_available'] ?? true),
                'sort_order' => (int) ($option['sort_order'] ?? $optionIndex),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function syncMenuItemOptions(MenuItem $item, MenuItemPart $part, array $options): void
    {
        foreach ($options as $optionIndex => $option) {
            $menuItemId = (int) ($option['menu_item_id'] ?? 0);
            $this->assertMenuItem($menuItemId, $item->id);

            $part->options()->create([
                'menu_item_id' => $menuItemId,
                'extra_price' => round((float) ($option['extra_price'] ?? 0), 2),
                'is_available' => (bool) ($option['is_available'] ?? true),
                'sort_order' => (int) ($option['sort_order'] ?? $optionIndex),
            ]);
        }
    }

    /**
     * The part row's menu_item_id is the parent item. Clients send that value
     * back on update, so it is not the default dish unless it points elsewhere.
     *
     * @param  array<string, mixed>  $partData
     */
    private function defaultMenuItemId(array $partData, int $parentId): ?int
    {
        if (isset($partData['default_menu_item_id']) && is_numeric($partData['default_menu_item_id'])) {
            return (int) $partData['default_menu_item_id'];
        }

        $nested = $partData['default_menu_item']['id'] ?? null;

        if (is_numeric($nested)) {
            return (int) $nested;
        }

        if (isset($partData['menu_item_id']) && is_numeric($partData['menu_item_id'])) {
            $id = (int) $partData['menu_item_id'];

            if ($id !== $parentId) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  Collection<int, MenuItemPart>  $existing
     * @return list<array<string, mixed>>
     */
    private function fillEchoedPartDefaults(int $parentId, array $parts, Collection $existing): array
    {
        foreach ($parts as $index => $partData) {
            $type = (string) ($partData['type'] ?? '');

            if (! in_array($type, [MenuItemPart::TYPE_FIXED, MenuItemPart::TYPE_SWAP], true)) {
                continue;
            }

            if ($this->defaultMenuItemId($partData, $parentId) !== null) {
                continue;
            }

            $remembered = $this->rememberedDefaultId($partData, $existing);

            if ($remembered !== null) {
                $parts[$index]['default_menu_item_id'] = $remembered;
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $partData
     * @param  Collection<int, MenuItemPart>  $existing
     */
    private function rememberedDefaultId(array $partData, Collection $existing): ?int
    {
        $partId = isset($partData['id']) && is_numeric($partData['id']) ? (int) $partData['id'] : 0;

        if ($partId > 0) {
            $match = $existing->firstWhere('id', $partId);

            if ($match instanceof MenuItemPart && $match->default_menu_item_id !== null) {
                return (int) $match->default_menu_item_id;
            }
        }

        $type = (string) ($partData['type'] ?? '');
        $name = isset($partData['name_ar']) ? (string) $partData['name_ar'] : '';

        if ($name === '') {
            return null;
        }

        $named = $existing->filter(
            fn (MenuItemPart $part): bool => $part->type === $type
                && $part->name_ar === $name
                && $part->default_menu_item_id !== null,
        );

        if ($named->count() !== 1) {
            return null;
        }

        return (int) $named->first()->default_menu_item_id;
    }

    private function assertMenuItem(int $menuItemId, int $parentId): void
    {
        if ($menuItemId < 1 || $menuItemId === $parentId) {
            throw new InvalidArgumentException('Menu item not found for item part.');
        }

        if (MenuItem::query()->whereKey($menuItemId)->doesntExist()) {
            throw new InvalidArgumentException('Menu item not found for item part.');
        }
    }

    private function assertWeightIngredient(int $ingredientId): Ingredient
    {
        $ingredient = Ingredient::query()->find($ingredientId);

        if (! $ingredient instanceof Ingredient) {
            throw new InvalidArgumentException('Ingredient not found for weight part.');
        }

        if (! in_array((string) $ingredient->unit, MenuItemPart::WEIGHT_UNITS, true)) {
            throw new InvalidArgumentException('Weight parts can only use ingredients sold by g or kg.');
        }

        return $ingredient;
    }

    private function assertCompatibleUnits(string $partUnit, string $ingredientUnit): void
    {
        try {
            Ingredient::convertQuantity(1, $partUnit, $ingredientUnit);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(__('Cannot convert :part_unit to :ingredient_unit.', [
                'part_unit' => $partUnit,
                'ingredient_unit' => $ingredientUnit,
            ]));
        }
    }

    /** @return list<string> */
    public function relations(): array
    {
        return [
            'category',
            'media',
            'parts.options.menuItem',
            'parts.options.ingredient',
            'parts.defaultMenuItem',
            'parts.ingredient',
        ];
    }
}
