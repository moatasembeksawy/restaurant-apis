<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 250.00,
        'created_at' => now(),
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('adds rules-based narrative when llm is disabled', function (): void {
    config(['intelligence.llm.enabled' => false]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/ai-summary?narrative=1')
        ->assertOk();

    expect($response->json('data.narrative_source'))->toBe('rules');
    expect($response->json('data.narrative.summary_en'))->toContain('Weekly revenue');
});

it('uses openai narrative when configured', function (): void {
    config([
        'intelligence.llm.enabled' => true,
        'intelligence.llm.api_key' => 'test-key',
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'summary_ar' => 'ملخص تجريبي',
                        'summary_en' => 'Sample executive summary',
                        'recommendations' => ['Increase QR promotions'],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/ai-summary?narrative=1')
        ->assertOk();

    expect($response->json('data.narrative_source'))->toBe('openai');
    expect($response->json('data.narrative.summary_en'))->toBe('Sample executive summary');
});
