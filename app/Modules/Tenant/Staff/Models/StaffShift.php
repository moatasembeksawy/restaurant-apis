<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffShift extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'clock_in',
        'clock_out',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->clock_out === null;
    }
}
