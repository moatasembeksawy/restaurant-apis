<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Models;

use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
