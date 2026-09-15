<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use Illuminate\Support\Str;
use InvalidArgumentException;

class IngredientService
{
    public function findOrCreate(
        ?int $ingredientId,
        ?string $nameAr,
        ?string $nameEn,
        ?string $unit,
        ?float $defaultCost = null,
        ?int $tenantId = null,
    ): Ingredient {
        if ($ingredientId !== null) {
            $ingredient = Ingredient::query()->find($ingredientId);

            if (! $ingredient) {
                throw new InvalidArgumentException('Ingredient not found.');
            }

            return $ingredient;
        }

        if ($nameAr === null || $nameAr === '' || $unit === null || $unit === '') {
            throw new InvalidArgumentException('Name and unit are required when ingredient_id is omitted.');
        }

        $existing = Ingredient::query()
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where('name_ar', $nameAr)
            ->where('unit', $unit)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Ingredient::create([
            'tenant_id' => $tenantId,
            'sku' => $this->uniqueSku($nameEn, $unit, $tenantId),
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'unit' => $unit,
            'default_cost' => $defaultCost ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{
     *     branch_id?: int|null,
     *     ingredient_id?: int|null,
     *     name_ar?: string|null,
     *     name_en?: string|null,
     *     unit?: string|null,
     *     current_stock?: float|int|string|null,
     *     reorder_level?: float|int|string|null,
     *     unit_cost?: float|int|string|null,
     *     default_cost?: float|int|string|null,
     *     is_active?: bool
     * }  $data
     */
    public function openAtBranch(array $data): InventoryStock
    {
        $unitCost = isset($data['unit_cost']) ? (float) $data['unit_cost'] : null;
        $ingredient = $this->findOrCreate(
            ingredientId: isset($data['ingredient_id']) ? (int) $data['ingredient_id'] : null,
            nameAr: isset($data['name_ar']) ? (string) $data['name_ar'] : null,
            nameEn: isset($data['name_en']) ? (string) $data['name_en'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            defaultCost: isset($data['default_cost']) ? (float) $data['default_cost'] : $unitCost,
        );

        $branchId = array_key_exists('branch_id', $data) && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;

        $alreadyOpen = InventoryStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->when(
                $branchId !== null,
                fn ($q) => $q->where('branch_id', $branchId),
                fn ($q) => $q->whereNull('branch_id'),
            )
            ->exists();

        if ($alreadyOpen) {
            throw new InvalidArgumentException('This item already exists at that branch.');
        }

        if ($unitCost !== null && (float) $ingredient->default_cost === 0.0) {
            $ingredient->update(['default_cost' => $unitCost]);
        }

        return InventoryStock::create([
            'ingredient_id' => $ingredient->id,
            'branch_id' => $branchId,
            'current_stock' => $data['current_stock'] ?? 0,
            'reorder_level' => $data['reorder_level'] ?? 0,
            'unit_cost' => $unitCost ?? $ingredient->default_cost,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function stockAtBranch(int $ingredientId, ?int $branchId): ?InventoryStock
    {
        return InventoryStock::query()
            ->where('ingredient_id', $ingredientId)
            ->when(
                $branchId !== null,
                fn ($q) => $q->where('branch_id', $branchId),
                fn ($q) => $q->whereNull('branch_id'),
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateMaster(Ingredient $ingredient, array $changes): Ingredient
    {
        $identity = array_intersect_key($changes, array_flip([
            'name_ar',
            'name_en',
            'unit',
            'default_cost',
            'is_active',
        ]));

        if ($identity !== []) {
            $ingredient->update($identity);
        }

        return $ingredient->fresh() ?? $ingredient;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateStock(InventoryStock $stock, array $changes): InventoryStock
    {
        $local = array_intersect_key($changes, array_flip(['reorder_level', 'is_active', 'unit_cost']));

        if ($local !== []) {
            $stock->update($local);
        }

        return $stock->fresh(['ingredient']) ?? $stock;
    }

    private function uniqueSku(?string $nameEn, string $unit, ?int $tenantId = null): string
    {
        $ascii = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', Str::ascii((string) $nameEn)));
        $base = $ascii !== '' ? substr($ascii, 0, 12) : 'ING';
        $suffix = strtoupper($unit);
        $candidate = $base.'-'.$suffix;
        $n = 1;

        while (Ingredient::query()
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where('sku', $candidate)
            ->exists()) {
            $n++;
            $candidate = $base.'-'.$suffix.'-'.$n;

            if ($n > 100) {
                $candidate = $base.'-'.$suffix.'-'.Str::upper(Str::random(4));
                break;
            }
        }

        return $candidate;
    }
}
