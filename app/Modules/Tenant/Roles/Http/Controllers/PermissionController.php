<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Http\Controllers;

use App\Modules\Tenant\Roles\Http\Resources\PermissionResource;
use App\Modules\Tenant\Roles\Services\RoleService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Roles & Permissions
 */
class PermissionController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * List every permission the API can enforce.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PermissionResource::collection($this->roles->permissions()),
        );
    }

    /**
     * Show one permission.
     */
    public function show(int $permission): JsonResponse
    {
        return ApiResponse::success(
            new PermissionResource($this->roles->findPermission($permission)),
        );
    }
}
