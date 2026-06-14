<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'from_branch_id',
        'to_branch_id',
        'from_ingredient_id',
        'to_ingredient_id',
        'user_id',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'from_ingredient_id');
    }

    public function toIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'to_ingredient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
