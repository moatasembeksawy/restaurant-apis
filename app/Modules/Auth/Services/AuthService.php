<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\SystemRoles;
use App\Shared\Support\Phone\PhoneNormalizer;
use App\Shared\Support\Scopes\TenantScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    private const MAX_LOGIN_CANDIDATES = 5;

    private static ?string $dummyHash = null;

    /**
     * Authenticate a manager/owner via email or phone + password.
     * Returns a scoped Sanctum token based on the user's role.
     *
     * @throws AuthenticationException
     */
    public function loginWithPassword(string $login, string $password, string $deviceName): array
    {
        $byEmail = str_contains($login, '@');

        $query = User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_active', true);

        $byEmail
            ? $query->where('email', $login)
            : $query->where('phone', PhoneNormalizer::normalize($login));

        // Phone numbers are not unique across tenants; only accept an unambiguous match.
        $candidates = $query->limit(self::MAX_LOGIN_CANDIDATES)->get();

        if ($candidates->isEmpty()) {
            // Spend the same hashing time as a real check so response timing doesn't reveal unknown accounts.
            Hash::check($password, self::dummyHash());

            throw new AuthenticationException('Invalid credentials.');
        }

        $matches = $candidates->filter(
            fn (User $u): bool => is_string($u->password) && Hash::check($password, $u->password),
        );

        if ($matches->count() !== 1) {
            throw new AuthenticationException('Invalid credentials.');
        }

        /** @var User $user */
        $user = $matches->first();

        $token = $user->createToken($deviceName, $user->tokenAbilities());

        app()->instance('tenant', $user->tenant);
        AuditLogger::log('auth.login', $user, ['method' => 'password', 'identifier' => $byEmail ? 'email' : 'phone']);

        return $this->formatTokenResponse($user, $token);
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)));
    }

    /**
     * Authenticate branch staff via PIN.
     *
     * @throws AuthenticationException
     */
    public function loginWithPin(int $branchId, string $pin, string $deviceName): array
    {
        $tenant = app('tenant');

        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchId)
            ->where(function ($query): void {
                $query->whereIn('role', ['waiter', 'cashier', 'cook', 'storekeeper'])
                    ->orWhereNotIn('role', SystemRoles::NAMES);
            })
            ->where('is_active', true)
            ->get()
            ->first(fn (User $u) => Hash::check($pin, $u->pin));

        if (! $user) {
            throw new AuthenticationException('Invalid PIN or branch.');
        }

        $token = $user->createToken($deviceName, $user->tokenAbilities(), now()->addDays(7));

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

    /**
     * Replace the authenticated user's password after verifying the current one.
     * Other sessions are revoked; the current token stays valid.
     *
     * @throws InvalidArgumentException
     */
    public function updatePassword(User $user, string $currentPassword, string $password): void
    {
        if (! is_string($user->password) || ! Hash::check($currentPassword, $user->password)) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }

        $user->forceFill([
            'password' => $password,
        ])->save();

        $currentTokenId = $user->currentAccessToken()->getKey();

        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();

        AuditLogger::log('auth.password_updated', $user);
    }

    private function formatTokenResponse(User $user, NewAccessToken $token, bool $isDevice = false): array
    {
        $user->loadMissing('tenant', 'branch');

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toISOString(),
            'abilities' => $token->accessToken->abilities,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'permissions' => $user->permissionNames(),
                'tenant_id' => $user->tenant_id,
                'branch_id' => $user->branch_id,
            ],
            'tenant' => $isDevice ? null : [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'description' => $user->tenant->description,
                'logo_url' => $user->tenant->logoUrl(),
                'plan' => $user->tenant->plan,
                'locale' => $user->tenant->locale,
                'qr_menu_token' => $user->branch?->qr_menu_token,
                'qr_menu_url' => $user->branch?->qrMenuUrl(),
            ],
        ];
    }
}
