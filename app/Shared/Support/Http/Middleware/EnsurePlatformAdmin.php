<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Modules\Platform\Models\PlatformAdmin;
use App\Shared\Support\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof PlatformAdmin || ! $user->is_active) {
            return ApiResponse::error(
                'Platform admin access required.',
                'PLATFORM_ADMIN_REQUIRED',
                403,
            );
        }

        Auth::setUser($user);

        return $next($request);
    }
}
