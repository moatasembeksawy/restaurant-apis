<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Exceptions\PhoneVerificationException;
use App\Modules\Auth\Http\Requests\PhoneForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\PhoneResetPasswordRequest;
use App\Modules\Auth\Http\Requests\VerifyPhoneRequest;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Authentication
 *
 * Phone OTP flows. Disabled (503 PHONE_VERIFICATION_DISABLED) until
 * PHONE_VERIFICATION_ENABLED=true and an OTP provider is configured.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly PhoneVerificationService $verification) {}

    /**
     * Send phone verification code
     *
     * Sends a one-time code to the authenticated user's phone.
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $sent = $this->verification->sendVerificationCode($request->user());
        } catch (PhoneVerificationException $e) {
            return $this->error($e);
        }

        return ApiResponse::success(null, $sent ? 'Verification code sent.' : 'Phone already verified.');
    }

    /**
     * Verify phone
     *
     * Confirms the authenticated user's phone with the code they received.
     */
    public function verify(VerifyPhoneRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $this->verification->verify($user, $request->string('code')->toString());
        } catch (PhoneVerificationException $e) {
            return $this->error($e);
        }

        return ApiResponse::success([
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at?->toISOString(),
        ], 'Phone verified.');
    }

    /**
     * Forgot password (phone)
     *
     * Sends a reset code to a verified phone. The response is identical whether or not the phone exists.
     *
     * @unauthenticated
     */
    public function forgotPassword(PhoneForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->verification->sendPasswordResetCode($request->string('phone')->toString());
        } catch (PhoneVerificationException $e) {
            return $this->error($e);
        }

        return ApiResponse::success(null, 'If that phone is registered and verified, a reset code has been sent.');
    }

    /**
     * Reset password (phone)
     *
     * Sets a new password using the code sent to the phone. All sessions are revoked.
     *
     * @unauthenticated
     */
    public function resetPassword(PhoneResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->verification->resetPassword(
                phone: $request->string('phone')->toString(),
                code: $request->string('code')->toString(),
                password: $request->string('password')->toString(),
            );
        } catch (PhoneVerificationException $e) {
            return $this->error($e);
        }

        return ApiResponse::success(null, 'Password has been reset.');
    }

    private function error(PhoneVerificationException $e): JsonResponse
    {
        $response = ApiResponse::error($e->getMessage(), $e->errorCode, $e->status);

        if ($e->retryAfter !== null) {
            $response->header('Retry-After', (string) $e->retryAfter);
        }

        return $response;
    }
}
