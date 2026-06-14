<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config(['rate_limits.plans.starter' => 2]);

    $this->tenant = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;

    RateLimiter::clear('tenant-api:'.$this->tenant->id);
});

it('returns 429 when tenant exceeds api rate limit', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/branches')->assertOk();
    $this->withToken($this->token)->getJson('/api/v1/branches')->assertOk();

    $this->withToken($this->token)
        ->getJson('/api/v1/branches')
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'RATE_LIMIT_EXCEEDED');
});

it('does not apply tenant rate limit to public routes', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/webhook/paymob?hmac=invalid', [])
            ->assertBadRequest();
    }
});
