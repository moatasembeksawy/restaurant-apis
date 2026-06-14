<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Inventory — Ingredients
 */
class IngredientController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $ingredients = Ingredient::query()
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name_ar')
            ->paginate((int) $request->query('per_page', '50'));

        return ApiResponse::success($ingredients);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'in:kg,g,l,ml,piece'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ingredient = Ingredient::create([
            ...$validated,
            'current_stock' => $validated['current_stock'] ?? 0,
            'reorder_level' => $validated['reorder_level'] ?? 0,
            'unit_cost' => $validated['unit_cost'] ?? 0,
        ]);

        return ApiResponse::created($ingredient, 'Ingredient created.');
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        return ApiResponse::success($ingredient->load('branch'));
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'unit' => ['sometimes', 'in:kg,g,l,ml,piece'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ingredient->update($validated);

        return ApiResponse::success($ingredient, 'Ingredient updated.');
    }

    public function lowStock(Request $request): JsonResponse
    {
        $items = $this->stock->lowStockIngredients(
            branchId: $request->query('branch_id') ? (int) $request->query('branch_id') : null,
        );

        return ApiResponse::success($items);
    }
}
