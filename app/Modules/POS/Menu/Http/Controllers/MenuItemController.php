<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Controllers;

use App\Modules\POS\Menu\Http\Requests\IndexMenuItemRequest;
use App\Modules\POS\Menu\Http\Requests\StoreMenuItemRequest;
use App\Modules\POS\Menu\Http\Requests\UpdateMenuItemRequest;
use App\Modules\POS\Menu\Http\Requests\UploadMenuItemPhotoRequest;
use App\Modules\POS\Menu\Http\Resources\MenuItemResource;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Services\MenuItemService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Menu Items
 */
class MenuItemController extends Controller
{
    public function __construct(private readonly MenuItemService $items) {}

    public function index(IndexMenuItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $items = MenuItem::query()
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['available_only'] ?? null, fn ($q) => $q->where('is_available', true))
            ->with($this->items->relations())
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(MenuItemResource::collection($items));
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        try {
            $item = $this->items->create($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'MENU_ITEM_VALIDATION_FAILED', 422);
        }

        return ApiResponse::created(new MenuItemResource($item), 'Menu item created.');
    }

    public function show(MenuItem $item): JsonResponse
    {
        return ApiResponse::success(new MenuItemResource($item->load($this->items->relations())));
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $item): JsonResponse
    {
        try {
            $item = $this->items->update($item, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'MENU_ITEM_VALIDATION_FAILED', 422);
        }

        return ApiResponse::success(new MenuItemResource($item), 'Menu item updated.');
    }

    public function uploadPhoto(UploadMenuItemPhotoRequest $request, MenuItem $item): JsonResponse
    {
        $item->clearMediaCollection('photo');
        $media = $item->addMediaFromRequest('photo')->toMediaCollection('photo');
        $item->update(['photo_url' => $media->getUrl()]);

        return ApiResponse::success(new MenuItemResource($item->fresh()->load($this->items->relations())), 'Photo uploaded.');
    }

    public function deletePhoto(MenuItem $item): JsonResponse
    {
        $item->clearMediaCollection('photo');
        $item->update(['photo_url' => null]);

        return ApiResponse::success(new MenuItemResource($item->fresh()->load($this->items->relations())), 'Photo removed.');
    }

    public function destroy(MenuItem $item): Response
    {
        $item->delete();

        return ApiResponse::noContent();
    }

    public function toggle(MenuItem $item): JsonResponse
    {
        $item->update(['is_available' => ! $item->is_available]);

        $state = $item->is_available ? 'available' : 'unavailable';

        return ApiResponse::success(
            new MenuItemResource($item->load($this->items->relations())),
            __('Item marked as :state.', ['state' => $state]),
        );
    }
}
