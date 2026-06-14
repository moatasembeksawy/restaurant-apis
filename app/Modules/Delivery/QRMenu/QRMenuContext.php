<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu;

use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

readonly class QRMenuContext
{
    public function __construct(
        public Tenant $tenant,
        public Branch $branch,
        public ?FloorTable $table = null,
    ) {}

    public function isTableLinked(): bool
    {
        return $this->table !== null;
    }
}
