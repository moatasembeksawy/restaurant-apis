<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Scopes\TenantScope;
use App\Shared\Support\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'email',
        'phone',
        'password',
        'pin',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ── Token abilities by role ────────────────────────────────────────────────

    public function tokenAbilities(): array
    {
        return match ($this->role) {
            'owner' => ['*'],
            'manager' => [
                'tables:*', 'menu:*', 'orders:*', 'kitchen:*',
                'billing:*', 'reports:read', 'users:read', 'audit:read',
            ],
            'cashier' => ['tables:read', 'menu:read', 'orders:*', 'billing:*'],
            'waiter' => ['tables:*', 'menu:read', 'orders:create', 'orders:update'],
            'cook' => ['kitchen:*', 'orders:read'],
            'rider' => ['deliveries:*'],
            default => [],
        };
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withoutGlobalScope(TenantScope::class);
    }
}
