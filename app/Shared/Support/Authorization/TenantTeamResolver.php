<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class TenantTeamResolver implements PermissionsTeamResolver
{
    protected int|string|null $teamId = null;

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->teamId = $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->teamId !== null) {
            return $this->teamId;
        }

        if (! app()->bound('tenant')) {
            return null;
        }

        $tenant = app('tenant');

        return $tenant instanceof Tenant ? $tenant->id : null;
    }
}
