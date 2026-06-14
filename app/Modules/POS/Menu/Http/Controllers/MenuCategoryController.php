<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Controllers;

use App\Modules\POS\Menu\Http\Requests\StoreMenuCategoryRequest;
use App\Modules\POS\Menu\Http\Requests\UpdateMenuCategoryRequest;
use App\Modules\POS\Menu\Http\Resources\MenuCategoryResource;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Menu Categories
 */
class MenuCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = MenuCategory::query()
            ->with('availableItems')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(MenuCategoryResource::collection($categories));
    }

    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = MenuCategory::create($validated);

        return ApiResponse::created(new MenuCategoryResource($category), 'Category created.');
    }

    public function show(MenuCategory $category): JsonResponse
    {
        return ApiResponse::success(new MenuCategoryResource($category->load('items')));
    }

    public function update(UpdateMenuCategoryRequest $request, MenuCategory $category): JsonResponse
    {
        $validated = $request->validated();

        $category->update($validated);

        return ApiResponse::success(new MenuCategoryResource($category), 'Category updated.');
    }

    public function destroy(MenuCategory $category): JsonResponse
    {
        $category->delete();

        return ApiResponse::noContent();
    }
}
