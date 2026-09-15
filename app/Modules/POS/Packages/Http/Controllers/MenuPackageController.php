<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Http\Controllers;

use App\Modules\POS\Packages\Http\Requests\IndexMenuPackageRequest;
use App\Modules\POS\Packages\Http\Requests\StoreMenuPackageRequest;
use App\Modules\POS\Packages\Http\Requests\UpdateMenuPackageRequest;
use App\Modules\POS\Packages\Http\Requests\UploadMenuPackagePhotoRequest;
use App\Modules\POS\Packages\Http\Resources\MenuPackageResource;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Packages\Services\PackageService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Menu Packages
 */
class MenuPackageController extends Controller
{
    public function __construct(private readonly PackageService $packages) {}

    public function index(IndexMenuPackageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $packages = MenuPackage::query()
            ->with(['slots.options.menuItem', 'slots.menuItem', 'category', 'media'])
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['available_only'] ?? null, fn ($q) => $q->where('is_available', true))
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(MenuPackageResource::collection($packages));
    }

    public function store(StoreMenuPackageRequest $request): JsonResponse
    {
        try {
            $package = $this->packages->create($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PACKAGE_VALIDATION_FAILED', 422);
        }

        return ApiResponse::created(new MenuPackageResource($package), 'Package created.');
    }

    public function show(MenuPackage $package): JsonResponse
    {
        return ApiResponse::success(new MenuPackageResource(
            $package->load(['slots.options.menuItem', 'slots.menuItem', 'category', 'media']),
        ));
    }

    public function update(UpdateMenuPackageRequest $request, MenuPackage $package): JsonResponse
    {
        try {
            $package = $this->packages->update($package, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PACKAGE_VALIDATION_FAILED', 422);
        }

        return ApiResponse::success(new MenuPackageResource($package), 'Package updated.');
    }

    public function uploadPhoto(UploadMenuPackagePhotoRequest $request, MenuPackage $package): JsonResponse
    {
        $package->clearMediaCollection('photo');
        $media = $package->addMediaFromRequest('photo')->toMediaCollection('photo');
        $package->update(['photo_url' => $media->getUrl()]);

        return ApiResponse::success(
            new MenuPackageResource($package->fresh()->load(['slots.options.menuItem', 'category', 'media'])),
            'Photo uploaded.',
        );
    }

    public function deletePhoto(MenuPackage $package): JsonResponse
    {
        $package->clearMediaCollection('photo');
        $package->update(['photo_url' => null]);

        return ApiResponse::success(
            new MenuPackageResource($package->fresh()->load(['slots.options.menuItem', 'category'])),
            'Photo removed.',
        );
    }

    public function destroy(MenuPackage $package): Response
    {
        $package->delete();

        return ApiResponse::noContent();
    }

    public function toggle(MenuPackage $package): JsonResponse
    {
        $package->update(['is_available' => ! $package->is_available]);
        $state = $package->is_available ? 'available' : 'unavailable';

        return ApiResponse::success(
            new MenuPackageResource($package->load(['slots.options.menuItem', 'category'])),
            "Package marked as {$state}.",
        );
    }
}
