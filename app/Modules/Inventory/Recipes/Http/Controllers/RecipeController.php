<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Recipes\Http\Controllers;

use App\Modules\Inventory\Recipes\Http\Requests\RecipeCostRequest;
use App\Modules\Inventory\Recipes\Http\Requests\SyncRecipeRequest;
use App\Modules\Inventory\Recipes\Http\Resources\RecipeCostResource;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Finance\Services\ItemCostAllocationService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Recipes
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ItemCostAllocationService $itemCosts,
    ) {}

    public function show(RecipeCostRequest $request, MenuItem $item): JsonResponse
    {
        return $this->costResponse($request, $item);
    }

    public function cost(RecipeCostRequest $request, MenuItem $item): JsonResponse
    {
        return $this->costResponse($request, $item);
    }

    public function sync(SyncRecipeRequest $request, MenuItem $item): JsonResponse
    {
        if (! app('tenant')->hasFeature('recipe_costing')) {
            return ApiResponse::error('Recipe costing requires Pro plan.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        try {
            $this->stock->syncRecipe($item, $request->validated('lines'));
            $cost = $this->pricedRecipe($request, $item->fresh());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'RECIPE_ERROR', 422);
        }

        return ApiResponse::success(new RecipeCostResource($cost), 'Recipe saved.');
    }

    private function costResponse(RecipeCostRequest $request, MenuItem $item): JsonResponse
    {
        try {
            $cost = $this->pricedRecipe($request, $item);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ITEM_COST_ALLOCATION_FAILED', 422);
        }

        return ApiResponse::success(new RecipeCostResource($cost));
    }

    /** @return array<string, mixed> */
    private function pricedRecipe(Request $request, MenuItem $item): array
    {
        return $this->itemCosts->decorateFoodCost(
            $this->stock->recipeCost($item),
            $request->user(),
            $request->input('branch_id'),
            $request->input('start_date'),
            $request->input('end_date'),
        );
    }
}
