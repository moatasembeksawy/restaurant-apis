<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Offers\Models\Offer;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name_ar' => 'عرض '.fake()->word(),
            'name_en' => fake()->words(2, true).' offer',
            'type' => Offer::TYPE_PERCENTAGE,
            'value' => 10,
            'target_type' => Offer::TARGET_ORDER,
            'target_id' => null,
            'code' => null,
            'channels' => ['dine_in', 'qr', 'whatsapp', 'own_delivery'],
            'is_active' => true,
            'stackable' => false,
        ];
    }

    public function coupon(string $code = 'RAMADAN'): self
    {
        return $this->state([
            'code' => $code,
            'name_ar' => 'كوبون '.$code,
        ]);
    }

    public function fixed(float $amount = 20): self
    {
        return $this->state([
            'type' => Offer::TYPE_FIXED,
            'value' => $amount,
        ]);
    }
}
