<?php

declare(strict_types=1);

namespace App\Shared\Support\Traits;

use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if (empty($model->tenant_id) && app()->bound('tenant')) {
                $model->tenant_id = app('tenant')?->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(mixed $query, int $tenantId): mixed
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId);
    }
}
