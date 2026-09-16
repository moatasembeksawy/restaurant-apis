<?php

declare(strict_types=1);

use App\Modules\Tenant\Models\Tenant;

it('keeps package quota at zero on starter without the feature', function (): void {
    $tenant = new Tenant(['plan' => 'starter', 'feature_flags' => []]);

    expect($tenant->planLimits()['max_packages'])->toBe(0);
    expect($tenant->planLimits()['max_active_offers'])->toBe(0);
});

it('grants package quota when menu_packages is enabled via feature flags', function (): void {
    $tenant = new Tenant([
        'plan' => 'starter',
        'feature_flags' => ['menu_packages'],
    ]);

    expect($tenant->hasFeature('menu_packages'))->toBeTrue();
    expect($tenant->planLimits()['max_packages'])->toBe(30);
});

it('grants offer quota when offers is enabled via feature flags', function (): void {
    $tenant = new Tenant([
        'plan' => 'growth',
        'feature_flags' => ['offers'],
    ]);

    expect($tenant->hasFeature('offers'))->toBeTrue();
    expect($tenant->planLimits()['max_active_offers'])->toBe(20);
});
