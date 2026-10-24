<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Auth\Notifications\VerifyEmailNotification;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Authorization\RolePermissionService;
use App\Shared\Support\Phone\PhoneNormalizer;
use App\Shared\Support\Scopes\TenantScope;
use App\Shared\Support\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property Carbon|null $phone_verified_at
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            app(RolePermissionService::class)->syncUserRole($user);
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('role')) {
                app(RolePermissionService::class)->syncUserRole($user);
            }
        });

        static::saving(function (User $user): void {
            if ($user->exists && $user->isDirty('phone') && ! $user->isDirty('phone_verified_at')) {
                $user->phone_verified_at = null;
            }
        });
    }

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
        'deactivation_reason',
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
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => PhoneNormalizer::normalize($value),
        );
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill(['phone_verified_at' => $this->freshTimestamp()])->save();
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
            default => $this->tenant_id ? $this->permissionNames() : [],
        };
    }

    /** @return list<string> */
    private function permissionNames(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->tenant_id);

        try {
            return $this->getAllPermissions()->pluck('name')->sort()->values()->all();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withoutGlobalScope(TenantScope::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function preferredLocale(): string
    {
        $tenant = $this->tenant;

        if ($tenant === null) {
            return (string) config('app.locale', 'en');
        }

        return $tenant->locale ?? (string) config('app.locale', 'en');
    }
}
