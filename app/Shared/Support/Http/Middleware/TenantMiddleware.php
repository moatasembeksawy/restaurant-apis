<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json([
                'errors' => [['message' => 'Tenant not found.', 'code' => 'TENANT_NOT_FOUND']],
            ], Response::HTTP_NOT_FOUND);
        }

        $tenant = $this->subscriptions->resolveBillingState($tenant);

        if ($tenant->status === 'suspended' && ! $this->allowsSuspendedAccess($request)) {
            return response()->json([
                'errors' => [[
                    'message' => 'Account suspended. Please renew your subscription.',
                    'code' => 'ACCOUNT_SUSPENDED',
                    'upgrade_url' => url('/api/v1/subscription/upgrade'),
                ]],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($tenant->status === 'grace_period') {
            $request->headers->set('X-Account-Warning', 'grace_period');
            $request->headers->set(
                'X-Grace-Period-Ends-At',
                $tenant->grace_period_ends_at?->toISOString() ?? '',
            );
        }

        app()->instance('tenant', $tenant);

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        if ($user = $request->user()) {
            return $user->tenant;
        }

        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) >= 2) {
            $subdomain = $parts[0];

            return Tenant::query()
                ->where('subdomain', $subdomain)
                ->orWhere('custom_domain', $host)
                ->first();
        }

        return null;
    }

    private function allowsSuspendedAccess(Request $request): bool
    {
        return $request->is('api/v1/subscription', 'api/v1/subscription/*');
    }
}
