<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\DeviceLoginRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\KitchenDeviceRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Resources\AuthenticatedUserResource;
use App\Modules\Auth\Http\Resources\AuthTokenResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Auth\Services\PasswordResetService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Authentication
 *
 * All authentication endpoints. No tenant middleware required.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Login (manager / owner)
     *
     * Authenticate with email and password. Returns a Sanctum Bearer token
     * scoped to the user's role abilities.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->loginWithPassword(
                email: $request->string('email')->toString(),
                password: $request->string('password')->toString(),
                deviceName: $request->string('device_name', 'web')->toString(),
            );

            return ApiResponse::success(new AuthTokenResource($result), 'Login successful.');
        } catch (AuthenticationException) {
            return ApiResponse::error('Invalid credentials.', 'INVALID_CREDENTIALS', 401);
        }
    }

    /**
     * Device login (waiter / cashier)
     *
     * Authenticate with a branch-scoped PIN. Issues a 12-hour token.
     * Requires TenantMiddleware to have resolved the tenant first.
     *
     * @unauthenticated
     */
    public function deviceLogin(DeviceLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->loginWithPin(
                branchId: (int) $request->integer('branch_id'),
                pin: $request->string('pin')->toString(),
                deviceName: $request->string('device_name', 'tablet')->toString(),
            );

            return ApiResponse::success(new AuthTokenResource($result), 'Device login successful.');
        } catch (AuthenticationException) {
            return ApiResponse::error('Invalid PIN or branch.', 'INVALID_PIN', 401);
        }
    }

    /**
     * Kitchen display login
     *
     * Creates a long-lived (1 year) read-only token for a kitchen display device.
     * Token abilities are limited to `kitchen:read` and `orders:read`.
     *
     * @unauthenticated
     */
    public function kitchenLogin(KitchenDeviceRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->loginKitchenDevice(
                branchId: (int) $request->integer('branch_id'),
                deviceSecret: $request->string('device_secret')->toString(),
                deviceName: $request->string('device_name', 'kitchen-display')->toString(),
            );

            return ApiResponse::success(new AuthTokenResource($result), 'Kitchen device registered.');
        } catch (AuthenticationException) {
            return ApiResponse::error('Invalid device secret.', 'INVALID_DEVICE_SECRET', 401);
        }
    }

    /**
     * Get authenticated user
     *
     * Returns the currently authenticated user, their role, and tenant info.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant', 'branch');

        return ApiResponse::success(new AuthenticatedUserResource([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'phone' => $user->phone,
            'role' => $user->role,
            'abilities' => $user->currentAccessToken()->abilities,
            'branch' => $user->branch ? [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'name_ar' => $user->branch->name_ar,
            ] : null,
            'tenant' => [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'plan' => $user->tenant->plan,
                'status' => $user->tenant->status,
                'locale' => $user->tenant->locale,
            ],
        ]));
    }

    /**
     * Logout
     *
     * Revokes the current Bearer token. All other tokens remain valid.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->revokeCurrentToken($request->user());

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    public function forgotPassword(ForgotPasswordRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        $passwordReset->sendResetLink($request->string('email')->toString());

        return ApiResponse::success(
            null,
            'If that email exists, a password reset link has been sent.',
        );
    }

    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        try {
            $passwordReset->resetPassword(
                email: $request->string('email')->toString(),
                token: $request->string('token')->toString(),
                password: $request->string('password')->toString(),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PASSWORD_RESET_FAILED', 422);
        }

        return ApiResponse::success(null, 'Password has been reset.');
    }

    public function verifyEmail(Request $request, EmailVerificationService $verification): JsonResponse
    {
        try {
            $user = $verification->verify($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'EMAIL_VERIFICATION_FAILED', 422);
        }

        return ApiResponse::success([
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ], 'Email verified.');
    }

    public function sendVerificationNotification(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email already verified.');
        }

        $verification->sendVerificationLink($user);

        return ApiResponse::success(null, 'Verification link sent.');
    }
}
