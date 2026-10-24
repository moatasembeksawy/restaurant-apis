<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Models\User;
use App\Shared\Support\Authorization\AssignableRole;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Phone\UniqueUserPhone;
use App\Shared\Support\Phone\ValidPhone;

class UpdateStaffRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => [
                'nullable', 'string', 'max:20',
                new ValidPhone(mobileOnly: true),
                new UniqueUserPhone(ignoreUserId: $this->staffUserId()),
            ],
            'role' => ['sometimes', 'string', 'max:50', new AssignableRole],
            'branch_id' => ['sometimes', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function staffUserId(): ?int
    {
        $user = $this->route('staff');

        return match (true) {
            $user instanceof User => (int) $user->getKey(),
            is_numeric($user) => (int) $user,
            default => null,
        };
    }
}
