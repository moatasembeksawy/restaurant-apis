<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Models;

use App\Models\User;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, string>|null $channels
 * @property array<int, string>|null $fulfillment_types
 * @property array<int, int>|null $days_of_week
 */
class Offer extends BaseModel
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TARGET_ORDER = 'order';

    public const TARGET_CATEGORY = 'category';

    public const TARGET_MENU_ITEM = 'menu_item';

    public const TARGET_PACKAGE = 'package';

    protected static function newFactory(): Factory
    {
        return OfferFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name_ar',
        'name_en',
        'type',
        'value',
        'target_type',
        'target_id',
        'code',
        'channels',
        'fulfillment_types',
        'starts_at',
        'ends_at',
        'days_of_week',
        'start_time',
        'end_time',
        'min_subtotal',
        'max_discount',
        'max_redemptions',
        'max_per_customer',
        'is_active',
        'stackable',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'channels' => 'array',
            'fulfillment_types' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'days_of_week' => 'array',
            'min_subtotal' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'is_active' => 'boolean',
            'stackable' => 'boolean',
        ];
    }

    public function isCoupon(): bool
    {
        return filled($this->code);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OfferRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(OfferRedemption::class);
    }
}
