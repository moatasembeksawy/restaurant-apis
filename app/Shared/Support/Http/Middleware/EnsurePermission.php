<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Models\User;
use App\Shared\Support\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasPermissionTo($permission, 'web')) {
            return ApiResponse::error(
                'You do not have permission to perform this action.',
                'FORBIDDEN',
                403,
            );
        }

        return $next($request);
    }
}
