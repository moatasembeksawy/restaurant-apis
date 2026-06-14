<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\LowStockIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateIngredientRequest;
use App\Modules\Inventory\Stock\Http\Resources\IngredientResource;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Inventory — Ingredients
 */
class IngredientController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(IndexIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ingredients = Ingredient::query()
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['active'] ?? null, fn ($q) => $q->where('is_active', true))
            ->orderBy('name_ar')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($ingredients, IngredientResource::class);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ingredient = Ingredient::create([
            ...$validated,
            'current_stock' => $validated['current_stock'] ?? 0,
            'reorder_level' => $validated['reorder_level'] ?? 0,
            'unit_cost' => $validated['unit_cost'] ?? 0,
        ]);

        return ApiResponse::created(new IngredientResource($ingredient), 'Ingredient created.');
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        return ApiResponse::success(new IngredientResource($ingredient->load('branch')));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $ingredient->update($request->validated());

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
