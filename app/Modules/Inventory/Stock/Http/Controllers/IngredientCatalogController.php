<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexIngredientCatalogRequest;
use App\Modules\Inventory\Stock\Http\Resources\IngredientCatalogResource;
use App\Modules\Inventory\Stock\Models\IngredientCatalog;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Inventory — Ingredient Catalog
 */
class IngredientCatalogController extends Controller
{
    public function index(IndexIngredientCatalogRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $catalogs = IngredientCatalog::query()
            ->orderBy('name_ar')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($catalogs, IngredientCatalogResource::class);
    }
}
