<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Services;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use InvalidArgumentException;

class OfferService
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Offer
    {
        $this->normalize($data);
        $this->assertTarget($data);

        if (($data['is_active'] ?? true) === true) {
            $this->planLimits->check('active_offers');
        }

        return Offer::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Offer $offer, array $data): Offer
    {
        $this->normalize($data);

        $merged = array_merge($offer->only([
            'target_type', 'target_id', 'type', 'value', 'code',
        ]), $data);
        $this->assertTarget($merged);

        $activating = array_key_exists('is_active', $data)
            && $data['is_active'] === true
            && ! $offer->is_active;

        if ($activating) {
            $this->planLimits->check('active_offers');
        }

        $offer->update($data);

        return $offer->fresh() ?? $offer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalize(array &$data): void
    {
        if (array_key_exists('code', $data)) {
            $code = is_string($data['code']) ? strtoupper(trim($data['code'])) : null;
            $data['code'] = $code === '' ? null : $code;
        }

        if (array_key_exists('channels', $data) && is_array($data['channels'])) {
            $data['channels'] = array_values($data['channels']);
        }

        if (array_key_exists('fulfillment_types', $data) && is_array($data['fulfillment_types'])) {
            $data['fulfillment_types'] = array_values($data['fulfillment_types']);
        }

        if (array_key_exists('days_of_week', $data) && is_array($data['days_of_week'])) {
            $data['days_of_week'] = array_values(array_map('intval', $data['days_of_week']));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertTarget(array $data): void
    {
        $type = (string) ($data['target_type'] ?? '');
        $id = isset($data['target_id']) ? (int) $data['target_id'] : null;

        if ($type === Offer::TARGET_ORDER) {
            return;
        }

        if ($id === null || $id < 1) {
            throw new InvalidArgumentException('target_id is required for this offer target.');
        }

        $exists = match ($type) {
            Offer::TARGET_CATEGORY => MenuCategory::query()->whereKey($id)->exists(),
            Offer::TARGET_MENU_ITEM => MenuItem::query()->whereKey($id)->exists(),
            Offer::TARGET_PACKAGE => MenuPackage::query()->whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new InvalidArgumentException('Offer target was not found.');
        }
    }
}
