<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\RegisterTenantRequest;
use App\Modules\Tenant\Services\TenantOnboardingService;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Onboarding
 */
class OnboardingController extends Controller
{
    public function register(
        RegisterTenantRequest $request,
        TenantOnboardingService $onboarding,
        SubscriptionService $subscriptions,
    ): JsonResponse {
        try {
            $result = $onboarding->register($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'REGISTRATION_FAILED', 422);
        }

        $owner = $result['owner'];
        $tenant = $result['tenant'];
        $token = $owner->createToken('onboarding', $owner->tokenAbilities());

        app()->instance('tenant', $tenant);

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
                'app_url' => $this->tenantAppUrl($tenant->subdomain),
            ],
            'branch' => [
                'id' => $result['branch']->id,
                'name' => $result['branch']->name,
                'name_ar' => $result['branch']->name_ar,
            ],
            'kitchen_device_secret' => $result['kitchen_device_secret'],
            'subscription' => $subscriptions->currentPlanDetails($tenant),
        ], 'Restaurant registered successfully. Your trial has started.');
    }

    private function tenantAppUrl(string $subdomain): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $host = parse_url($base, PHP_URL_HOST) ?: 'localhost';

        return str_replace($host, "{$subdomain}.{$host}", $base);
    }
}
