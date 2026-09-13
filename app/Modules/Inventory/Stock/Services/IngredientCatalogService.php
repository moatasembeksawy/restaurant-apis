<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\IngredientCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

class IngredientCatalogService
{
    public function findOrCreate(
        ?int $catalogId,
        ?string $nameAr,
        ?string $nameEn,
        ?string $unit,
        ?int $tenantId = null,
    ): IngredientCatalog {
        if ($catalogId !== null) {
            $catalog = IngredientCatalog::query()->find($catalogId);

            if (! $catalog) {
                throw new InvalidArgumentException('Ingredient catalog not found.');
            }

            return $catalog;
        }

        if ($nameAr === null || $nameAr === '' || $unit === null || $unit === '') {
            throw new InvalidArgumentException('Name and unit are required when catalog_id is omitted.');
        }

        $existing = IngredientCatalog::query()
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where('name_ar', $nameAr)
            ->where('unit', $unit)
            ->first();

        if ($existing) {
            return $existing;
        }

        return IngredientCatalog::create([
            'tenant_id' => $tenantId,
            'sku' => $this->uniqueSku($nameEn, $unit, $tenantId),
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'unit' => $unit,
        ]);
    }

    /**
     * @param  array{
     *     branch_id?: int|null,
     *     catalog_id?: int|null,
     *     name_ar?: string|null,
     *     name_en?: string|null,
     *     unit?: string|null,
     *     current_stock?: float|int|string|null,
     *     reorder_level?: float|int|string|null,
     *     unit_cost?: float|int|string|null,
     *     is_active?: bool
     * }  $data
     */
    public function openAtBranch(array $data): Ingredient
    {
        $catalog = $this->findOrCreate(
            catalogId: isset($data['catalog_id']) ? (int) $data['catalog_id'] : null,
            nameAr: isset($data['name_ar']) ? (string) $data['name_ar'] : null,
            nameEn: isset($data['name_en']) ? (string) $data['name_en'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
        );

        $branchId = array_key_exists('branch_id', $data) && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;

        $alreadyOpen = Ingredient::query()
            ->where('catalog_id', $catalog->id)
            ->when(
                $branchId !== null,
                fn ($q) => $q->where('branch_id', $branchId),
                fn ($q) => $q->whereNull('branch_id'),
            )
            ->exists();

        if ($alreadyOpen) {
            throw new InvalidArgumentException('This item already exists at that branch.');
        }

        return Ingredient::create([
            'catalog_id' => $catalog->id,
            'branch_id' => $branchId,
            'name_ar' => $catalog->name_ar,
            'name_en' => $catalog->name_en,
            'unit' => $catalog->unit,
            'current_stock' => $data['current_stock'] ?? 0,
            'reorder_level' => $data['reorder_level'] ?? 0,
            'unit_cost' => $data['unit_cost'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function syncIdentity(Ingredient $ingredient, array $changes): Ingredient
    {
        $identity = array_intersect_key($changes, array_flip(['name_ar', 'name_en', 'unit']));
        $local = array_intersect_key($changes, array_flip(['reorder_level', 'is_active']));

        if ($identity !== [] && $ingredient->catalog_id) {
            $catalog = $ingredient->catalog;

            if (! $catalog instanceof IngredientCatalog) {
                $catalog = IngredientCatalog::query()->whereKey($ingredient->catalog_id)->first();
            }

            if ($catalog instanceof IngredientCatalog) {
                $catalog->update($identity);

                $synced = array_intersect_key($catalog->only(['name_ar', 'name_en', 'unit']), $identity);

                if ($synced !== []) {
                    Ingredient::query()
                        ->where('catalog_id', $catalog->id)
                        ->update($synced);
                }
            }
        }

        if ($local !== []) {
            $ingredient->update($local);
        }

        return $ingredient->fresh(['catalog']) ?? $ingredient;
    }

    private function uniqueSku(?string $nameEn, string $unit, ?int $tenantId = null): string
    {
        $ascii = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', Str::ascii((string) $nameEn)));
        $base = $ascii !== '' ? substr($ascii, 0, 12) : 'ING';
        $suffix = strtoupper($unit);
        $candidate = $base.'-'.$suffix;
        $n = 1;

        while (IngredientCatalog::query()
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
