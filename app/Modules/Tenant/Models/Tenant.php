<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Models;

use App\Models\User;
use App\Modules\Tenant\Subscription\Models\Subscription;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return TenantFactory::new();
    }

    protected $fillable = [
        'name',
        'subdomain',
        'custom_domain',
        'custom_domain_verification_token',
        'custom_domain_verified_at',
        'locale',
        'plan',
        'status',
        'eta_cert_path',
        'eta_client_id',
        'eta_client_secret',
        'eta_taxpayer_id',
        'eta_branch_id',
        'kitchen_device_secret',
        'whatsapp_phone_number_id',
        'talabat_webhook_secret',
        'elmenus_webhook_secret',
        'feature_flags',
        'trial_ends_at',
        'grace_period_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
            'trial_ends_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'custom_domain_verified_at' => 'datetime',
            'eta_client_secret' => 'encrypted',
        ];
    }

    // ── Plan limits ────────────────────────────────────────────────────────────

    public function planLimits(): array
    {
        return match ($this->plan) {
            'enterprise' => [
                'max_users' => PHP_INT_MAX,
                'max_branches' => PHP_INT_MAX,
                'max_orders_per_month' => PHP_INT_MAX,
            ],
            'pro' => [
                'max_users' => 15,
                'max_branches' => 1,
                'max_orders_per_month' => 10000,
            ],
            'growth' => [
                'max_users' => 5,
                'max_branches' => 1,
                'max_orders_per_month' => 2000,
            ],
            default => [ // starter
                'max_users' => 2,
                'max_branches' => 1,
                'max_orders_per_month' => 500,
            ],
        };
    }

    public function hasFeature(string $feature): bool
    {
        // Explicit feature flag override takes precedence
        if (in_array($feature, $this->feature_flags ?? [])) {
            return true;
        }

        return $this->planIncludesFeature($feature);
    }

    private function planIncludesFeature(string $feature): bool
    {
        $starterFeatures = ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice'];
        $growthFeatures = [...$starterFeatures, 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders'];
        $proFeatures = [...$growthFeatures, 'inventory', 'recipe_costing', 'suppliers', 'staff_shifts', 'audit_log', 'waste_log'];
        $enterpriseFeatures = [...$proFeatures, 'multi_branch', 'ai_reports', 'loyalty', 'whatsapp_marketing', 'aggregator_analytics'];

        return match ($this->plan) {
            'enterprise' => in_array($feature, $enterpriseFeatures),
            'pro' => in_array($feature, $proFeatures),
            'growth' => in_array($feature, $growthFeatures),
            default => in_array($feature, $starterFeatures),
        };
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function defaultBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->where('is_default', true);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany('created_at');
    }
}
