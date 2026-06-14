<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $tenant || ! $tenant->hasFeature($feature)) {
            return response()->json([
                'errors' => [[
                    'message' => "Your plan does not include the '{$feature}' feature. Please upgrade.",
                    'code' => 'FEATURE_NOT_AVAILABLE',
                    'upgrade_url' => url('/api/v1/subscription/upgrade'),
                ]],
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $next($request);
    }
}
