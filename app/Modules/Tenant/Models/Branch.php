<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Models;

use App\Shared\Support\Traits\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Branch extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function newFactory(): Factory
    {
        return BranchFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'name_ar',
        'address',
        'phone',
        'is_default',
        'timezone',
        'printing_mode',
        'is_active',
        'qr_menu_token',
        'tax_rate',
        'tax_rate_applies_to',
        'service_charge_rate',
        'service_charge_applies_to',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'tax_rate' => 'decimal:2',
            'tax_rate_applies_to' => 'array',
            'service_charge_rate' => 'decimal:2',
            'service_charge_applies_to' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $branch): void {
            if (empty($branch->qr_menu_token)) {
                $branch->qr_menu_token = Str::random(48);
            }
        });
    }

    public function qrMenuUrl(): ?string
    {
        if (! $this->qr_menu_token) {
            return null;
        }

        return rtrim((string) config('app.url'), '/')."/api/v1/qr/{$this->qr_menu_token}/menu";
    }
}
