<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Services;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Models\MenuItemPart;
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
                foreach ($item->parts()->with('options')->get() as $part) {
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
        $menuItemId = $this->defaultMenuItemId($partData);
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
        $menuItemId = $this->defaultMenuItemId($partData);
        $this->assertMenuItem($menuItemId, $item->id);

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
     * @param  array<string, mixed>  $partData
     */
    private function defaultMenuItemId(array $partData): int
    {
        foreach (['default_menu_item_id', 'menu_item_id'] as $key) {
            if (isset($partData[$key]) && is_numeric($partData[$key])) {
                return (int) $partData[$key];
            }
        }

        return 0;
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
            throw new InvalidArgumentException("Cannot convert {$partUnit} to {$ingredientUnit}.");
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
