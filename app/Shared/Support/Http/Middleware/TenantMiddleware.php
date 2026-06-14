<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Support\Tenant\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantResolver $tenantResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantResolver->resolve($request);

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
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        return $next($request);
    }

    private function allowsSuspendedAccess(Request $request): bool
    {
        return $request->is('api/v1/subscription', 'api/v1/subscription/*');
    }
}
