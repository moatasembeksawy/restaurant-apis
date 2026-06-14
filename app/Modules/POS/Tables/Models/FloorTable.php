<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Models;

use App\Shared\Domain\Models\BaseModel;
use Database\Factories\FloorTableFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FloorTable extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return FloorTableFactory::new();
    }
    protected $table = 'floor_tables';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'section',
        'capacity',
        'status',
        'qr_token',
        'qr_url',
        'position_x',
        'position_y',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $table): void {
            if (empty($table->qr_token)) {
                $table->qr_token = Str::random(48);
            }
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\App\Modules\POS\Orders\Models\Order::class, 'floor_table_id');
    }

    public function activeOrder(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Modules\POS\Orders\Models\Order::class, 'floor_table_id')
            ->whereIn('status', ['pending', 'active', 'cooking', 'ready']);
    }
}
