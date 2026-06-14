<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Modules\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class TenantRateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        $limit = (int) (config('rate_limits.plans.'.$tenant->plan)
            ?? config('rate_limits.default_per_minute', 60));

        $key = 'tenant-api:'.$tenant->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'errors' => [[
                    'message' => "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after' => $retryAfter,
                    'limit_per_minute' => $limit,
                ]],
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit);
            $response->headers->set(
                'X-RateLimit-Remaining',
                (string) max(0, $limit - RateLimiter::attempts($key)),
            );
        }

        return $response;
    }
}
