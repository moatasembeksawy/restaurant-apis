<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends BaseModel
{
    public const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'wallet'];

    public const STATUSES = ['pending', 'approved', 'voided'];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'expense_category_id',
        'staff_shift_id',
        'cash_movement_id',
        'created_by',
        'approved_by',
        'voided_by',
        'amount',
        'payment_method',
        'description',
        'reference',
        'receipt_path',
        'expense_date',
        'status',
        'approved_at',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(StaffShift::class, 'staff_shift_id');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
