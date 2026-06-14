<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Models\User;
use App\Modules\Tenant\Http\Requests\IndexStaffRequest;
use App\Modules\Tenant\Http\Requests\StoreStaffRequest;
use App\Modules\Tenant\Http\Requests\UpdateStaffRequest;
use App\Modules\Tenant\Http\Resources\StaffResource;
use App\Modules\Tenant\Services\StaffService;
use App\Modules\Tenant\Subscription\Exceptions\PlanLimitExceededException;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Staff Management
 */
class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(IndexStaffRequest $request): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        $validated = $request->validated();

        return ApiResponse::success(
            StaffResource::collection(
                $this->staff->list(
                    branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                ),
            ),
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        try {
            $user = $this->staff->create($request->validated(), $request->user());
        } catch (PlanLimitExceededException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_CREATE_FAILED', 422);
        }

        return ApiResponse::created(new StaffResource($user), 'Staff member created.');
    }

    public function show(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        return ApiResponse::success(new StaffResource($this->staff->formatUser($staff)));
    }

    public function update(UpdateStaffRequest $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        try {
            $user = $this->staff->update($staff, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_UPDATE_FAILED', 422);
        }

        return ApiResponse::success(new StaffResource($user), 'Staff member updated.');
    }

    public function deactivate(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        try {
            $user = $this->staff->deactivate($staff, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_DEACTIVATE_FAILED', 422);
        }

        return ApiResponse::success(new StaffResource($user), 'Staff member deactivated.');
    }

    private function authorizeStaffManagement(Request $request): void
    {
        if (! $request->user()->can('staff.manage')) {
            throw new HttpResponseException(
                ApiResponse::error('You do not have permission to manage staff.', 'FORBIDDEN', 403),
            );
        }
    }
}
