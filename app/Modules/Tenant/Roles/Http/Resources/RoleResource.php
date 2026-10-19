<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Http\Resources;

use App\Shared\Support\Authorization\SystemRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $permissions = $this->relationLoaded('permissions')
            ? $this->permissions
            : $this->permissions()->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => SystemRoles::isSystem((string) $this->name),
            'is_customized' => $this->getAttribute('tenant_id') !== null
                && SystemRoles::isSystem((string) $this->name),
            'permissions' => $permissions->pluck('name')->sort()->values()->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
