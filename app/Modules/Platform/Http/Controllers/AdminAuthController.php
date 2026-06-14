<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\AdminLoginRequest;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Platform\Services\PlatformAdminAuthService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Platform Admin — Authentication
 */
class AdminAuthController extends Controller
{
    public function __construct(private readonly PlatformAdminAuthService $auth) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->auth->login(
                email: $request->string('email')->toString(),
                password: $request->string('password')->toString(),
                deviceName: $request->string('device_name', 'admin-panel')->toString(),
            );
        } catch (AuthenticationException) {
            return ApiResponse::error('Invalid credentials.', 'INVALID_CREDENTIALS', 401);
        }

        return ApiResponse::success($result, 'Admin login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return ApiResponse::success($this->auth->formatAdmin($admin));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();
        $this->auth->logout($admin);

        return ApiResponse::success(null, 'Logged out.');
    }
}
