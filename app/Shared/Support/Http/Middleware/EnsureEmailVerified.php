<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Models\User;
use App\Shared\Support\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.verification.enforce', false)) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            return ApiResponse::error(
                'Email address must be verified before accessing this resource.',
                'EMAIL_NOT_VERIFIED',
                403,
            );
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->is(
            'api/v1/subscription',
            'api/v1/subscription/*',
            'api/v1/settings',
            'api/v1/settings/*',
            'api/v1/auth/me',
            'api/v1/auth/email/verification-notification',
            'api/v1/auth/token',
        );
    }
}
