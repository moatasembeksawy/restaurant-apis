<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\IndexAdminTenantRequest;
use App\Modules\Platform\Http\Requests\StoreAdminTenantRequest;
use App\Modules\Platform\Http\Requests\UpdateAdminTenantFeaturesRequest;
use App\Modules\Platform\Http\Requests\UpdateAdminTenantPlanRequest;
use App\Modules\Platform\Http\Requests\UpdateAdminTenantRequest;
use App\Modules\Platform\Http\Requests\UpdateAdminTenantStatusRequest;
use App\Modules\Platform\Http\Resources\AdminTenantResource;
use App\Modules\Platform\Http\Resources\ImpersonationResource;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Platform\Services\TenantManagementService;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Platform Admin — Tenant Management
 */
class AdminTenantController extends Controller
{
    public function __construct(private readonly TenantManagementService $tenants) {}

    public function index(IndexAdminTenantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->tenants->list(
            filters: $validated,
            perPage: (int) ($validated['per_page'] ?? 20),
        );

        return ApiResponse::paginated($paginator, AdminTenantResource::class);
    }

    public function store(StoreAdminTenantRequest $request): JsonResponse
    {
        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $tenant = $this->tenants->create($request->validated(), $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_CREATE_FAILED', 422);
        }

        return ApiResponse::created(new AdminTenantResource($tenant), 'Tenant created.');
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return ApiResponse::success(new AdminTenantResource($this->tenants->show($tenant)));
    }

    public function update(UpdateAdminTenantRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->update($tenant, $request->validated(), $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_UPDATE_FAILED', 422);
        }

        return ApiResponse::success(new AdminTenantResource($result), 'Tenant updated.');
    }

    public function updatePlan(UpdateAdminTenantPlanRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->updatePlan($tenant, $request->string('plan')->toString(), $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_PLAN_FAILED', 422);
        }

        return ApiResponse::success(new AdminTenantResource($result), 'Tenant plan updated.');
    }

    public function updateStatus(UpdateAdminTenantStatusRequest $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validated();

        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->updateStatus(
                $tenant,
                $validated['status'],
                $admin,
                isset($validated['trial_days']) ? (int) $validated['trial_days'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_STATUS_FAILED', 422);
        }

        return ApiResponse::success(new AdminTenantResource($result), 'Tenant status updated.');
    }

    public function updateFeatures(UpdateAdminTenantFeaturesRequest $request, Tenant $tenant): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return ApiResponse::success(
            new AdminTenantResource(
                $this->tenants->updateFeatureFlags($tenant, $request->input('feature_flags'), $admin),
            ),
            'Feature flags updated.',
        );
    }

    public function impersonate(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->impersonate($tenant, $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'IMPERSONATION_FAILED', 422);
        }

        return ApiResponse::success(new ImpersonationResource($result), 'Impersonation token issued.');
    }
}
