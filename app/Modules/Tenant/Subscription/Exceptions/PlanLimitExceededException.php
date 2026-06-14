<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Exceptions;

use RuntimeException;

class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $resource,
        public readonly int $limit,
    ) {
        parent::__construct($message);
    }
}
