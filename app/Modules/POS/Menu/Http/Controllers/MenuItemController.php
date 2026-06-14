<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Menu Items
 */
class MenuItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = MenuItem::query()
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->query('available_only'), fn ($q) => $q->where('is_available', true))
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'preparation_time' => ['integer', 'min:1', 'max:120'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $item = MenuItem::create($validated);

        return ApiResponse::created($item, 'Menu item created.');
    }

    public function show(MenuItem $item): JsonResponse
    {
        return ApiResponse::success($item->load('category'));
    }

    public function update(Request $request, MenuItem $item): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'preparation_time' => ['sometimes', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'integer'],
        ]);

        $item->update($validated);

        return ApiResponse::success($item, 'Menu item updated.');
    }

    public function destroy(MenuItem $item): JsonResponse
    {
        $item->delete();

        return ApiResponse::noContent();
    }

    public function toggle(MenuItem $item): JsonResponse
    {
        $item->update(['is_available' => ! $item->is_available]);

        $state = $item->is_available ? 'available' : 'unavailable';

        return ApiResponse::success($item, "Item marked as {$state}.");
    }
}
