<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Services;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantOnboardingService
{
    /** @var list<string> */
    private const RESERVED_SUBDOMAINS = [
        'www', 'api', 'admin', 'app', 'mail', 'smtp', 'ftp', 'cdn', 'static',
        'dashboard', 'pos', 'kitchen', 'rider', 'webhook', 'webhooks',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array{tenant: Tenant, branch: Branch, owner: User, kitchen_device_secret: string}
     */
    public function register(array $data): array
    {
        $subdomain = Str::lower(Str::slug($data['subdomain']));

        $this->validateSubdomain($subdomain);

        if (Tenant::query()->where('subdomain', $subdomain)->exists()) {
            throw new InvalidArgumentException('This subdomain is already taken.');
        }

        if (User::query()->withoutGlobalScopes()->where('email', $data['owner_email'])->exists()) {
            throw new InvalidArgumentException('This email is already registered.');
        }

        $kitchenSecret = $data['kitchen_device_secret'] ?? Str::password(16);

        return DB::transaction(function () use ($data, $subdomain, $kitchenSecret): array {
            $trialDays = (int) config('billing.trial_days', 14);

            $tenant = Tenant::create([
                'name' => $data['restaurant_name'],
                'subdomain' => $subdomain,
                'locale' => $data['locale'] ?? 'ar',
                'plan' => 'starter',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays),
                'kitchen_device_secret' => Hash::make($kitchenSecret),
                'feature_flags' => [],
            ]);

            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $data['branch_name'] ?? 'Main Branch',
                'name_ar' => $data['branch_name_ar'] ?? ($data['branch_name'] ?? 'الفرع الرئيسي'),
                'address' => $data['branch_address'] ?? null,
                'phone' => $data['branch_phone'] ?? null,
                'timezone' => $data['timezone'] ?? 'Africa/Cairo',
                'is_default' => true,
                'is_active' => true,
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone' => $data['owner_phone'] ?? null,
                'password' => $data['owner_password'],
                'role' => 'owner',
                'is_active' => true,
            ]);

            app()->instance('tenant', $tenant);

            AuditLogger::log('tenant.registered', $tenant, [
                'subdomain' => $subdomain,
                'owner_id' => $owner->id,
                'branch_id' => $branch->id,
            ]);

            return [
                'tenant' => $tenant,
                'branch' => $branch,
                'owner' => $owner,
                'kitchen_device_secret' => $kitchenSecret,
            ];
        });
    }

    private function validateSubdomain(string $subdomain): void
    {
        if (strlen($subdomain) < 3) {
            throw new InvalidArgumentException('Subdomain must be at least 3 characters.');
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $subdomain)) {
            throw new InvalidArgumentException('Subdomain may only contain lowercase letters, numbers, and hyphens.');
        }

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            throw new InvalidArgumentException('This subdomain is reserved.');
        }
    }
}
