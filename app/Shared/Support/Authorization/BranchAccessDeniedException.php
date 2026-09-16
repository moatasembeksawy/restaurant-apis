<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use RuntimeException;

class BranchAccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You do not have access to this branch.');
    }
}
