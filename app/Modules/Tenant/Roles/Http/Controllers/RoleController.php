<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Http\Controllers;

use App\Modules\Tenant\Roles\Http\Requests\AttachRolePermissionsRequest;
use App\Modules\Tenant\Roles\Http\Requests\StoreRoleRequest;
use App\Modules\Tenant\Roles\Http\Requests\SyncRolePermissionsRequest;
use App\Modules\Tenant\Roles\Http\Requests\UpdateRoleRequest;
use App\Modules\Tenant\Roles\Http\Resources\RoleResource;
use App\Modules\Tenant\Roles\Services\RoleService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Roles & Permissions
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * List system roles and roles created by this restaurant.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            RoleResource::collection($this->roles->list()),
        );
    }

    /**
     * Show a role and its permissions.
     */
    public function show(int $role): JsonResponse
    {
        return ApiResponse::success(new RoleResource($this->roles->find($role)));
    }

    /**
     * Create a restaurant-specific role.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        try {
            $role = $this->roles->create($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::created(new RoleResource($role), 'Role created.');
    }

    /**
     * Rename a custom role or replace its permissions.
     */
    public function update(UpdateRoleRequest $request, int $role): JsonResponse
    {
        try {
            $updated = $this->roles->update($role, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::success(new RoleResource($updated), 'Role updated.');
    }

    /**
     * Delete a custom role, or reset a customized system role.
     */
    public function destroy(int $role): JsonResponse|Response
    {
        try {
            $this->roles->delete($role);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::noContent();
    }

    /**
     * Replace the permissions on a role.
     */
    public function syncPermissions(SyncRolePermissionsRequest $request, int $role): JsonResponse
    {
        try {
            $updated = $this->roles->syncPermissions($role, $request->validated('permissions'));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::success(new RoleResource($updated), 'Role permissions updated.');
    }

    /**
     * Add permissions to a role.
     */
    public function attachPermissions(AttachRolePermissionsRequest $request, int $role): JsonResponse
    {
        try {
            $updated = $this->roles->attachPermissions($role, $request->validated('permissions'));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::success(new RoleResource($updated), 'Permissions added.');
    }

    /**
     * Remove one permission from a role.
     */
    public function detachPermission(int $role, int $permission): JsonResponse
    {
        try {
            $updated = $this->roles->detachPermission($role, $permission);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ROLE_INVALID', 422);
        }

        return ApiResponse::success(new RoleResource($updated), 'Permission removed.');
    }
}
