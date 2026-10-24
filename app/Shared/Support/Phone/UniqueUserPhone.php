<?php

declare(strict_types=1);

namespace App\Shared\Support\Phone;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phone numbers identify users across all tenants (phone login), so uniqueness is global.
 */
final class UniqueUserPhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (self::isTaken($value, $this->ignoreUserId)) {
            $fail('validation.phone_taken')->translate();
        }
    }

    public static function isTaken(string $phone, ?int $ignoreUserId = null): bool
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('phone', PhoneNormalizer::normalize($phone))
            ->when($ignoreUserId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->exists();
    }
}
