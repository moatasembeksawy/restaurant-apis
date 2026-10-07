<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Controllers;

use App\Modules\POS\Menu\Http\Requests\StoreMenuCategoryRequest;
use App\Modules\POS\Menu\Http\Requests\UpdateMenuCategoryRequest;
use App\Modules\POS\Menu\Http\Requests\UploadMenuCategoryPhotoRequest;
use App\Modules\POS\Menu\Http\Resources\MenuCategoryResource;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * @group Menu Categories
 */
class MenuCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = MenuCategory::query()
            ->with('availableItems', 'media')
            ->where('is_visible', true);
        BranchAccess::constrainNullable($categories, $request->user());

        $categories = $categories
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
        return ApiResponse::success(new MenuCategoryResource($category->load('items', 'media')));
    }

    public function update(UpdateMenuCategoryRequest $request, MenuCategory $category): JsonResponse
    {
        $validated = $request->validated();

        $category->update($validated);

        return ApiResponse::success(new MenuCategoryResource($category->fresh()->load('media')), 'Category updated.');
    }

    public function uploadPhoto(UploadMenuCategoryPhotoRequest $request, MenuCategory $category): JsonResponse
    {
        $category->clearMediaCollection('photo');
        $media = $category->addMediaFromRequest('photo')->toMediaCollection('photo');
        $category->update(['photo_url' => $media->getUrl()]);

        return ApiResponse::success(new MenuCategoryResource($category->fresh()->load('media')), 'Photo uploaded.');
    }

    public function deletePhoto(MenuCategory $category): JsonResponse
    {
        $category->clearMediaCollection('photo');
        $category->update(['photo_url' => null]);

        return ApiResponse::success(new MenuCategoryResource($category->fresh()), 'Photo removed.');
    }

    public function destroy(MenuCategory $category): Response
    {
        $category->delete();

        return ApiResponse::noContent();
    }
}
