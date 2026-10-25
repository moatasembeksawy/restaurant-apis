<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

final class SystemRoles
{
    public const GUARD = 'web';

    /** @var list<string> */
    public const NAMES = ['owner', 'manager', 'cashier', 'waiter', 'cook', 'rider', 'storekeeper'];

    /** @var list<string> */
    public const ASSIGNABLE = ['manager', 'cashier', 'waiter', 'cook', 'rider', 'storekeeper'];

    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,48}$/';

    public static function isSystem(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
