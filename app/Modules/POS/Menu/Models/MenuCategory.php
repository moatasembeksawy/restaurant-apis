<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Models;

use App\Modules\POS\Packages\Models\MenuPackage;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuCategory extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return MenuCategoryFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name_ar',
        'name_en',
        'description_ar',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id');
    }

    public function availableItems(): HasMany
    {
        return $this->items()->where('is_available', true);
    }

    /** @return HasMany<MenuPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(MenuPackage::class, 'category_id');
    }

    /** @return HasMany<MenuPackage, $this> */
    public function availablePackages(): HasMany
    {
        return $this->packages()->where('is_available', true);
    }
}
