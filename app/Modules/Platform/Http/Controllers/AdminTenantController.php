<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

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

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(
            $this->tenants->list(
                filters: $validated,
                perPage: (int) ($validated['per_page'] ?? 20),
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:150'],
            'subdomain' => ['required', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['nullable', 'in:ar,en'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8', 'max:100'],
            'owner_phone' => ['nullable', 'string', 'max:20'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'branch_name_ar' => ['nullable', 'string', 'max:100'],
            'branch_address' => ['nullable', 'string', 'max:255'],
            'branch_phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
        ]);

        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $tenant = $this->tenants->create($validated, $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_CREATE_FAILED', 422);
        }

        return ApiResponse::created($tenant, 'Tenant created.');
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return ApiResponse::success($this->tenants->show($tenant));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'subdomain' => ['sometimes', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->update($tenant, $validated, $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_UPDATE_FAILED', 422);
        }

        return ApiResponse::success($result, 'Tenant updated.');
    }

    public function updatePlan(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
        ]);

        try {
            /** @var PlatformAdmin $admin */
            $admin = $request->user();
            $result = $this->tenants->updatePlan($tenant, $validated['plan'], $admin);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TENANT_PLAN_FAILED', 422);
        }

        return ApiResponse::success($result, 'Tenant plan updated.');
    }

    public function updateStatus(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,trial,grace_period,suspended'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

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

        return ApiResponse::success($result, 'Tenant status updated.');
    }

    public function updateFeatures(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'feature_flags' => ['required', 'array'],
            'feature_flags.*' => ['string', 'max:50'],
        ]);

        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return ApiResponse::success(
            $this->tenants->updateFeatureFlags($tenant, $validated['feature_flags'], $admin),
            'Feature flags updated.',
        );
    }
}
