<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Modules\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json([
                'errors' => [['message' => 'Tenant not found.', 'code' => 'TENANT_NOT_FOUND']],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($tenant->status === 'suspended') {
            return response()->json([
                'errors' => [['message' => 'Account suspended. Contact support.', 'code' => 'ACCOUNT_SUSPENDED']],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($tenant->status === 'grace_period') {
            $request->headers->set('X-Account-Warning', 'grace_period');
        }

        app()->instance('tenant', $tenant);

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        // Primary: resolve from the authenticated user's token
        if ($user = $request->user()) {
            return $user->tenant;
        }

        // Fallback: resolve from subdomain (used for public QR / webhook routes)
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 2) {
            $subdomain = $parts[0];

            return Tenant::query()
                ->where('subdomain', $subdomain)
                ->orWhere('custom_domain', $host)
                ->where('status', '!=', 'suspended')
                ->first();
        }

        return null;
    }
}
