<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Services\TenantOnboardingService;
use App\Modules\Tenant\Subscription\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantManagementService
{
    public function __construct(
        private readonly TenantOnboardingService $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->withCount(['users', 'branches'])
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['plan'])) {
            $query->where('plan', $filters['plan']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subdomain', 'like', "%{$search}%");
            });
        }

        return $query
            ->paginate($perPage)
            ->through(fn (Tenant $tenant) => $this->formatListItem($tenant));
    }

    /** @return array<string, mixed> */
    public function show(Tenant $tenant): array
    {
        $tenant->loadCount(['users', 'branches']);
        $tenant->load(['subscription']);
        $defaultBranch = Branch::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->first(['id', 'name', 'name_ar']);

        $ordersThisMonth = Order::query()
            ->where('tenant_id', $tenant->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $owner = User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->first(['id', 'name', 'email', 'phone', 'is_active']);

        return [
            ...$this->formatListItem($tenant),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'is_active' => $owner->is_active,
            ] : null,
            'default_branch' => $defaultBranch ? [
                'id' => $defaultBranch->id,
                'name' => $defaultBranch->name,
                'name_ar' => $defaultBranch->name_ar,
            ] : null,
            'subscription' => $tenant->subscription ? [
                'id' => $tenant->subscription->id,
                'plan' => $tenant->subscription->plan,
                'status' => $tenant->subscription->status,
                'current_period_end' => $tenant->subscription->current_period_end?->toIso8601String(),
            ] : null,
            'orders_this_month' => $ordersThisMonth,
            'limits' => $tenant->planLimits(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data, PlatformAdmin $admin): array
    {
        $result = $this->onboarding->register([
            'restaurant_name' => $data['restaurant_name'],
            'subdomain' => $data['subdomain'],
            'locale' => $data['locale'] ?? 'ar',
            'owner_name' => $data['owner_name'],
            'owner_email' => $data['owner_email'],
            'owner_password' => $data['owner_password'],
            'owner_phone' => $data['owner_phone'] ?? null,
            'branch_name' => $data['branch_name'] ?? null,
            'branch_name_ar' => $data['branch_name_ar'] ?? null,
            'branch_address' => $data['branch_address'] ?? null,
            'branch_phone' => $data['branch_phone'] ?? null,
            'timezone' => $data['timezone'] ?? 'Africa/Cairo',
        ]);

        if (! empty($data['plan']) && $data['plan'] !== 'starter') {
            $result['tenant']->update([
                'plan' => $data['plan'],
                'status' => $data['status'] ?? 'active',
                'trial_ends_at' => null,
            ]);
        }

        $this->logAdminAction($admin, 'admin.tenant.created', $result['tenant'], [
            'subdomain' => $result['tenant']->subdomain,
        ]);

        return $this->show($result['tenant']->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Tenant $tenant, array $data, PlatformAdmin $admin): array
    {
        $updates = array_filter([
            'name' => $data['name'] ?? null,
            'locale' => $data['locale'] ?? null,
            'custom_domain' => array_key_exists('custom_domain', $data) ? $data['custom_domain'] : null,
        ], fn ($value) => $value !== null);

        if (isset($data['subdomain']) && $data['subdomain'] !== $tenant->subdomain) {
            $subdomain = strtolower($data['subdomain']);

            if (Tenant::query()->where('subdomain', $subdomain)->where('id', '!=', $tenant->id)->exists()) {
                throw new InvalidArgumentException('This subdomain is already taken.');
            }

            $updates['subdomain'] = $subdomain;
        }

        if ($updates !== []) {
            $tenant->update($updates);
            $this->logAdminAction($admin, 'admin.tenant.updated', $tenant, ['changes' => array_keys($updates)]);
        }

        return $this->show($tenant->fresh());
    }

    /** @return array<string, mixed> */
    public function updatePlan(Tenant $tenant, string $plan, PlatformAdmin $admin): array
    {
        if (! in_array($plan, ['starter', 'growth', 'pro', 'enterprise'], true)) {
            throw new InvalidArgumentException('Invalid plan.');
        }

        $tenant->update(['plan' => $plan]);
        $this->logAdminAction($admin, 'admin.tenant.plan_changed', $tenant, ['plan' => $plan]);

        return $this->show($tenant->fresh());
    }

    /** @return array<string, mixed> */
    public function updateStatus(Tenant $tenant, string $status, PlatformAdmin $admin, ?int $trialDays = null): array
    {
        if (! in_array($status, ['active', 'trial', 'grace_period', 'suspended'], true)) {
            throw new InvalidArgumentException('Invalid status.');
        }

        $updates = ['status' => $status];

        if ($status === 'trial') {
            $updates['trial_ends_at'] = now()->addDays($trialDays ?? (int) config('billing.trial_days', 14));
            $updates['grace_period_ends_at'] = null;
        }

        if ($status === 'active') {
            $updates['grace_period_ends_at'] = null;
        }

        if ($status === 'grace_period') {
            $updates['grace_period_ends_at'] = now()->addDays((int) config('billing.grace_period_days', 7));
        }

        if ($status === 'suspended') {
            $tenant->users()
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'deactivation_reason' => 'tenant_suspended',
                ]);
            DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', $tenant->users()->pluck('id'))
                ->where('tokenable_type', User::class)
                ->delete();
        }

        if ($tenant->status === 'suspended' && $status !== 'suspended') {
            $tenant->users()
                ->where('deactivation_reason', 'tenant_suspended')
                ->update([
                    'is_active' => true,
                    'deactivation_reason' => null,
                ]);
        }

        $tenant->update($updates);
        $this->logAdminAction($admin, 'admin.tenant.status_changed', $tenant, ['status' => $status]);

        return $this->show($tenant->fresh());
    }

    /**
     * @param  list<string>  $featureFlags
     * @return array<string, mixed>
     */
    public function updateFeatureFlags(Tenant $tenant, array $featureFlags, PlatformAdmin $admin): array
    {
        $tenant->update(['feature_flags' => array_values(array_unique($featureFlags))]);
        $this->logAdminAction($admin, 'admin.tenant.features_updated', $tenant, ['feature_flags' => $featureFlags]);

        return $this->show($tenant->fresh());
    }

    /** @return array<string, mixed> */
    public function impersonate(Tenant $tenant, PlatformAdmin $admin): array
    {
        $owner = User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            throw new InvalidArgumentException('Tenant has no active owner.');
        }

        $expiresAt = now()->addMinutes((int) config('platform.impersonation_ttl_minutes', 60));

        $token = $owner->createToken(
            "admin-impersonation:{$admin->id}",
            $owner->tokenAbilities(),
            $expiresAt,
        );

        $this->logAdminAction($admin, 'admin.tenant.impersonated', $tenant, [
            'owner_id' => $owner->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'abilities' => $token->accessToken->abilities,
            'impersonated_by' => [
                'id' => $admin->id,
                'email' => $admin->email,
            ],
            'user' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role,
                'tenant_id' => $owner->tenant_id,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardStats(): array
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byPlan = Tenant::query()
            ->selectRaw('plan, COUNT(*) as count')
            ->groupBy('plan')
            ->pluck('count', 'plan');

        $newThisMonth = Tenant::query()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $mrrCents = Subscription::query()
            ->where('status', 'active')
            ->where('current_period_end', '>=', now())
            ->sum('amount_cents');

        return [
            'tenants' => [
                'total' => Tenant::query()->count(),
                'by_status' => $byStatus,
                'by_plan' => $byPlan,
                'new_this_month' => $newThisMonth,
            ],
            'revenue' => [
                'mrr_egp' => round($mrrCents / 100, 2),
                'active_subscriptions' => Subscription::query()->where('status', 'active')->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatListItem(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'custom_domain' => $tenant->custom_domain,
            'locale' => $tenant->locale,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'feature_flags' => $tenant->feature_flags ?? [],
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'grace_period_ends_at' => $tenant->grace_period_ends_at?->toIso8601String(),
            'users_count' => $tenant->users_count ?? $tenant->users()->count(),
            'branches_count' => $tenant->branches_count ?? $tenant->branches()->count(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function logAdminAction(PlatformAdmin $admin, string $description, Tenant $tenant, array $properties = []): void
    {
        activity('platform')
            ->causedBy($admin)
            ->performedOn($tenant)
            ->withProperties([
                'tenant_id' => $tenant->id,
                ...$properties,
            ])
            ->log($description);
    }
}
