<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class PlatformAdminAuthService
{
    /**
     * @throws AuthenticationException
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, string $deviceName): array
    {
        $admin = PlatformAdmin::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (! $admin || ! Hash::check($password, $admin->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $admin->update(['last_login_at' => now()]);

        $token = $admin->createToken($deviceName, $admin->tokenAbilities());

        return $this->formatTokenResponse($admin, $token);
    }

    public function logout(PlatformAdmin $admin): void
    {
        $admin->currentAccessToken()?->delete();
    }

    /** @return array<string, mixed> */
    public function formatAdmin(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatTokenResponse(PlatformAdmin $admin, NewAccessToken $token): array
    {
        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'admin' => $this->formatAdmin($admin),
        ];
    }
}
