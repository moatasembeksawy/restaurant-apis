<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Scopes\TenantScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    /**
     * Authenticate a manager/owner via email + password.
     * Returns a scoped Sanctum token based on the user's role.
     *
     * @throws AuthenticationException
     */
    public function loginWithPassword(string $email, string $password, string $deviceName): array
    {
        $user = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $token = $user->createToken($deviceName, $user->tokenAbilities());

        app()->instance('tenant', $user->tenant);
        AuditLogger::log('auth.login', $user, ['method' => 'password']);

        return $this->formatTokenResponse($user, $token);
    }

    /**
     * Authenticate a waiter/cashier via branch PIN.
     *
     * @throws AuthenticationException
     */
    public function loginWithPin(int $branchId, string $pin, string $deviceName): array
    {
        $tenant = app('tenant');

        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchId)
            ->whereIn('role', ['waiter', 'cashier', 'cook'])
            ->where('is_active', true)
            ->get()
            ->first(fn (User $u) => Hash::check($pin, $u->pin));

        if (! $user) {
            throw new AuthenticationException('Invalid PIN or branch.');
        }

        $token = $user->createToken($deviceName, $user->tokenAbilities(), now()->addHours(12));

        AuditLogger::log('auth.login', $user, ['method' => 'pin']);

        return $this->formatTokenResponse($user, $token);
    }

    /**
     * Create a long-lived read-only token for a kitchen display device.
     *
     * @throws AuthenticationException
     */
    public function loginKitchenDevice(int $branchId, string $deviceSecret, string $deviceName): array
    {
        $tenant = app('tenant');

        if (! Hash::check($deviceSecret, $tenant->kitchen_device_secret ?? '')) {
            throw new AuthenticationException('Invalid device secret.');
        }

        // Create a synthetic system user token for the kitchen display
        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->first();

        if (! $user) {
            throw new AuthenticationException('Tenant owner not found.');
        }

        $token = $user->createToken(
            $deviceName,
            ['kitchen:read', 'orders:read'],
            now()->addYear(),
        );

        return $this->formatTokenResponse($user, $token, isDevice: true);
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    private function formatTokenResponse(User $user, NewAccessToken $token, bool $isDevice = false): array
    {
        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toISOString(),
            'abilities' => $token->accessToken->abilities,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'tenant_id' => $user->tenant_id,
                'branch_id' => $user->branch_id,
            ],
            'tenant' => $isDevice ? null : [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'plan' => $user->tenant->plan,
                'locale' => $user->tenant->locale,
            ],
        ];
    }
}
