<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Models\User;
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

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        return ApiResponse::success(
            $this->staff->list(
                branchId: $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:manager,cashier,waiter,cook,rider'],
            'branch_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ]);

        try {
            $user = $this->staff->create($validated, $request->user());
        } catch (PlanLimitExceededException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_CREATE_FAILED', 422);
        }

        return ApiResponse::created($user, 'Staff member created.');
    }

    public function show(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        return ApiResponse::success($this->staff->formatUser($staff));
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'in:manager,cashier,waiter,cook,rider'],
            'branch_id' => ['sometimes', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $user = $this->staff->update($staff, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_UPDATE_FAILED', 422);
        }

        return ApiResponse::success($user, 'Staff member updated.');
    }

    public function deactivate(Request $request, User $staff): JsonResponse
    {
        $this->authorizeStaffManagement($request);

        try {
            $user = $this->staff->deactivate($staff, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STAFF_DEACTIVATE_FAILED', 422);
        }

        return ApiResponse::success($user, 'Staff member deactivated.');
    }

    private function authorizeStaffManagement(Request $request): void
    {
        if (! in_array($request->user()->role, ['owner', 'manager'], true)) {
            throw new HttpResponseException(
                ApiResponse::error('Only owners or managers can manage staff.', 'FORBIDDEN', 403),
            );
        }
    }
}
