<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return ApiResponse::success($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $category = MenuCategory::create($validated);

        return ApiResponse::created($category, 'Category created.');
    }

    public function show(MenuCategory $category): JsonResponse
    {
        return ApiResponse::success($category->load('items'));
    }

    public function update(Request $request, MenuCategory $category): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
        ]);

        $category->update($validated);

        return ApiResponse::success($category, 'Category updated.');
    }

    public function destroy(MenuCategory $category): JsonResponse
    {
        $category->delete();

        return ApiResponse::noContent();
    }
}
