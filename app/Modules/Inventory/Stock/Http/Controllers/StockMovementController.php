<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexStockMovementRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Stock\Http\Resources\IngredientResource;
use App\Modules\Inventory\Stock\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Http\Resources\ApiResponse;
use App\Shared\Support\Http\Resources\DataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group Inventory — Stock Movements
 */
class StockMovementController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(IndexStockMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $movements = StockMovement::query()
            ->with(['ingredient:id,name_ar,unit', 'user:id,name'])
            ->when($validated['ingredient_id'] ?? null, fn ($q, $id) => $q->where('ingredient_id', $id))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($movements, StockMovementResource::class);
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['type'] === 'waste' && ! app('tenant')->hasFeature('waste_log')) {
            return ApiResponse::error('Waste logging requires Pro plan.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        try {
            $movement = $this->stock->recordMovement(
                ingredient: $ingredient,
                type: $validated['type'],
                quantity: (float) $validated['quantity'],
                user: $request->user(),
                unitCost: isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null,
                notes: $validated['notes'] ?? null,
                adjustmentDirection: $validated['direction'] ?? 'in',
            );

            return ApiResponse::created(new DataResource([
                'movement' => (new StockMovementResource($movement))->resolve($request),
                'ingredient' => (new IngredientResource($ingredient->fresh()))->resolve($request),
            ]), 'Movement recorded.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_ERROR', 422);
        }
    }
}
