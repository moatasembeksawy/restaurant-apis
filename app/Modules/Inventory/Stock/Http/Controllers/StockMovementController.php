<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexStockMovementRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Stock\Http\Resources\InventoryStockResource;
use App\Modules\Inventory\Stock\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\IngredientService;
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
    public function __construct(
        private readonly StockService $stock,
        private readonly IngredientService $ingredients,
    ) {}

    public function index(IndexStockMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $movements = StockMovement::query()
            ->with(['ingredient', 'user:id,name'])
            ->when($validated['ingredient_id'] ?? null, fn ($q, $id) => $q->where('ingredient_id', $id))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
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

        $branchId = isset($validated['branch_id'])
            ? (int) $validated['branch_id']
            : $request->user()?->branch_id;

        $stockRow = $this->ingredients->stockAtBranch((int) $validated['ingredient_id'], $branchId);

        if (! $stockRow) {
            return ApiResponse::error('Ingredient is not stocked at this branch.', 'STOCK_ERROR', 422);
        }

        try {
            $movement = $this->stock->recordMovement(
                stock: $stockRow,
                type: $validated['type'],
                quantity: (float) $validated['quantity'],
                user: $request->user(),
                unitCost: isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null,
                notes: $validated['notes'] ?? null,
                branchId: $branchId,
                adjustmentDirection: $validated['direction'] ?? 'in',
            );

            return ApiResponse::created(new DataResource([
                'movement' => (new StockMovementResource($movement))->resolve($request),
                'ingredient' => (new InventoryStockResource($stockRow->fresh('ingredient')))->resolve($request),
            ]), 'Movement recorded.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_ERROR', 422);
        }
    }
}
