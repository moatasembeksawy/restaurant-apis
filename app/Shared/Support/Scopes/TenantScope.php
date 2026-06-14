<?php

declare(strict_types=1);

namespace App\Shared\Support\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if ($tenant) {
            $builder->where($model->getTable().'.tenant_id', $tenant->id);
        }
    }
}
