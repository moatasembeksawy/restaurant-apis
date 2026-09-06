<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurred_at
 * @property Carbon|null $reversed_at
 */
class CashMovement extends BaseModel
{
    public const TYPES = ['paid_in', 'paid_out', 'safe_drop', 'float_adjustment', 'reversal'];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'staff_shift_id',
        'created_by',
        'reversal_of_id',
        'reversed_by',
        'type',
        'amount',
        'reason',
        'reference',
        'occurred_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<StaffShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(StaffShift::class, 'staff_shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return BelongsTo<CashMovement, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
