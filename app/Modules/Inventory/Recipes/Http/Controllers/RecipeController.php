<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Recipes\Http\Controllers;

use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Inventory — Recipes
 */
class RecipeController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function show(MenuItem $item): JsonResponse
    {
        return ApiResponse::success($this->stock->recipeCost($item));
    }

    public function cost(MenuItem $item): JsonResponse
    {
        return ApiResponse::success($this->stock->recipeCost($item));
    }

    public function sync(Request $request, MenuItem $item): JsonResponse
    {
        if (! app('tenant')->hasFeature('recipe_costing')) {
            return ApiResponse::error('Recipe costing requires Pro plan.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $this->stock->syncRecipe($item, $validated['lines']);

        return ApiResponse::success($this->stock->recipeCost($item->fresh()), 'Recipe saved.');
    }
}
