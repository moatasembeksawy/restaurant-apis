<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AssignableRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tenantId = RoleAssignment::currentTenantId();

        if (! is_string($value) || $tenantId === null || ! RoleAssignment::isAssignable($value, $tenantId)) {
            $fail('The selected role is invalid.');
        }
    }
}
