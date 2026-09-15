<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Models;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\MenuPackageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MenuPackage extends BaseModel implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected static function newFactory(): Factory
    {
        return MenuPackageFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'category_id',
        'name_ar',
        'name_en',
        'description_ar',
        'price',
        'photo_url',
        'is_available',
        'preparation_time',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /** @return HasMany<MenuPackageSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(MenuPackageSlot::class, 'package_id')->orderBy('sort_order');
    }

    public function photoUrl(): ?string
    {
        return $this->getFirstMediaUrl('photo') ?: $this->photo_url;
    }

    public function isSellable(): bool
    {
        if (! $this->is_available) {
            return false;
        }

        $this->loadMissing(['slots.options.menuItem', 'slots.menuItem']);

        foreach ($this->slots as $slot) {
            if (! $slot->isSatisfiable()) {
                return false;
            }
        }

        return true;
    }
}
