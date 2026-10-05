<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\UpdateTenantSettingsRequest;
use App\Modules\Tenant\Http\Requests\UploadTenantLogoRequest;
use App\Modules\Tenant\Http\Resources\DomainStatusResource;
use App\Modules\Tenant\Http\Resources\TenantSettingsResource;
use App\Modules\Tenant\Services\CustomDomainService;
use App\Modules\Tenant\Services\TenantSettingsService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;

/**
 * @group Tenant Settings
 */
class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly CustomDomainService $domains,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return ApiResponse::success(new TenantSettingsResource($this->settings->show(app('tenant'))));
    }

    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            $result = $this->settings->update(
                tenant: app('tenant'),
                data: $request->validated(),
                updatedByUserId: $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SETTINGS_UPDATE_FAILED', 422);
        }

        return ApiResponse::success(new TenantSettingsResource($result), 'Settings updated.');
    }

    public function uploadLogo(UploadTenantLogoRequest $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $logo = $request->file('logo');

        if (! $logo instanceof UploadedFile) {
            return ApiResponse::error('A logo image is required.', 'LOGO_INVALID', 422);
        }

        $result = $this->settings->uploadLogo(
            tenant: app('tenant'),
            logo: $logo,
            updatedByUserId: $request->user()->id,
        );

        return ApiResponse::success(new TenantSettingsResource($result), 'Logo uploaded.');
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $result = $this->settings->deleteLogo(app('tenant'), $request->user()->id);

        return ApiResponse::success(new TenantSettingsResource($result), 'Logo removed.');
    }

    public function domainStatus(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return ApiResponse::success(new DomainStatusResource($this->domains->status(app('tenant'))));
    }

    public function verifyDomain(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            $tenant = $this->domains->verify(app('tenant'));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'DOMAIN_VERIFY_FAILED', 422);
        }

        return ApiResponse::success(new DomainStatusResource($this->domains->status($tenant)), 'Custom domain verified.');
    }

    private function authorizeOwner(Request $request): void
    {
        if ($request->user()->role !== 'owner') {
            throw new HttpResponseException(
                ApiResponse::error('Only the owner can manage tenant settings.', 'FORBIDDEN', 403),
            );
        }
    }
}
