<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\LowStockIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateIngredientRequest;
use App\Modules\Inventory\Stock\Http\Resources\IngredientResource;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Services\IngredientCatalogService;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Ingredients
 */
class IngredientController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly IngredientCatalogService $catalogs,
    ) {}

    public function index(IndexIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ingredients = Ingredient::query()
            ->with('catalog:id,sku,name_ar,unit')
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['catalog_id'] ?? null, fn ($q, $id) => $q->where('catalog_id', $id))
            ->when($validated['active'] ?? null, fn ($q) => $q->where('is_active', true))
            ->orderBy('name_ar')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($ingredients, IngredientResource::class);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        try {
            $ingredient = $this->catalogs->openAtBranch($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INGREDIENT_ERROR', 422);
        }

        return ApiResponse::created(
            new IngredientResource($ingredient->load('catalog')),
            'Ingredient created.',
        );
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        return ApiResponse::success(new IngredientResource($ingredient->load(['branch', 'catalog'])));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $ingredient = $this->catalogs->syncIdentity($ingredient, $request->validated());

        return ApiResponse::success(new IngredientResource($ingredient), 'Ingredient updated.');
    }

    public function lowStock(LowStockIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $items = $this->stock->lowStockIngredients(
            branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );

        return ApiResponse::success(IngredientResource::collection($items));
    }
}
