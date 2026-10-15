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
        'allocates_to_item_cost',
    ];

    protected $attributes = [
        'allocates_to_item_cost' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allocates_to_item_cost' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
