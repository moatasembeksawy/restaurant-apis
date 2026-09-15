<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Recipes\Http\Controllers;

use App\Modules\Inventory\Recipes\Http\Requests\SyncRecipeRequest;
use App\Modules\Inventory\Recipes\Http\Resources\RecipeCostResource;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Recipes
 */
class RecipeController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function show(MenuItem $item): JsonResponse
    {
        return ApiResponse::success(new RecipeCostResource($this->stock->recipeCost($item)));
    }

    public function cost(MenuItem $item): JsonResponse
    {
        return ApiResponse::success(new RecipeCostResource($this->stock->recipeCost($item)));
    }

    public function sync(SyncRecipeRequest $request, MenuItem $item): JsonResponse
    {
        if (! app('tenant')->hasFeature('recipe_costing')) {
            return ApiResponse::error('Recipe costing requires Pro plan.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        try {
            $this->stock->syncRecipe($item, $request->validated('lines'));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'RECIPE_ERROR', 422);
        }

        return ApiResponse::success(
            new RecipeCostResource($this->stock->recipeCost($item->fresh())),
            'Recipe saved.',
        );
    }
}
