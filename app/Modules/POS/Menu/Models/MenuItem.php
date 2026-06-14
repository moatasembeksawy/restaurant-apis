<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Models;

use App\Shared\Domain\Models\BaseModel;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MenuItem extends BaseModel implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected static function newFactory(): Factory
    {
        return MenuItemFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'category_id',
        'name_ar',
        'name_en',
        'description_ar',
        'price',
        'cost_price',
        'photo_url',
        'is_available',
        'preparation_time',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function profitMargin(): ?float
    {
        if (! $this->cost_price || $this->price <= 0) {
            return null;
        }

        return round((($this->price - $this->cost_price) / $this->price) * 100, 2);
    }

    public function photoUrl(): ?string
    {
        return $this->getFirstMediaUrl('photo') ?: $this->photo_url;
    }
}
