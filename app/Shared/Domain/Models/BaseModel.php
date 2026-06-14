<?php

declare(strict_types=1);

namespace App\Shared\Domain\Models;

use App\Shared\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use BelongsToTenant;
}
