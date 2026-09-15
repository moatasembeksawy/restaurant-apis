<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Services;

use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Offers\Models\OrderAdjustment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class OfferEngine
{
    /** @var list<string> */
    private const DEFAULT_CHANNELS = ['dine_in', 'qr', 'whatsapp', 'own_delivery'];

    public function sync(Order $order): void
    {
        $order->adjustments()
            ->whereIn('source', [OrderAdjustment::SOURCE_OFFER, OrderAdjustment::SOURCE_COUPON])
            ->delete();

        $tenant = app()->bound('tenant') ? app('tenant') : $order->tenant;

        if (! $tenant || ! $tenant->hasFeature('offers')) {
            return;
        }

        $lines = $order->pricedItems()->with(['menuItem', 'package'])->get();
        $candidates = $this->eligibleOffers($order, $lines, now());

        $auto = $candidates
            ->filter(fn (array $row): bool => $row['source'] === OrderAdjustment::SOURCE_OFFER)
            ->sortByDesc('amount')
            ->first();

        $coupon = $candidates
            ->first(fn (array $row): bool => $row['source'] === OrderAdjustment::SOURCE_COUPON);

        $applyAuto = $auto !== null;
        $applyCoupon = $coupon !== null;

        if ($applyAuto && $applyCoupon) {
            $autoOffer = $auto['offer'];
            $couponOffer = $coupon['offer'];
            if (! $autoOffer->stackable || ! $couponOffer->stackable) {
                $applyAuto = false;
            }
        }

        if ($applyAuto) {
            $this->writeAdjustment($order, $auto);
        }

        if ($applyCoupon) {
            $this->writeAdjustment($order, $coupon);
        }
    }

    /**
     * @param  array{
     *     channel: string,
     *     fulfillment_type?: string|null,
     *     coupon_code?: string|null,
     *     customer_id?: int|null,
     *     items: Collection<int, OrderItem>|list<OrderItem>
     * }  $cart
     * @return array{discount: float, adjustments: list<array{source: string, offer_id: int, amount: float, label: string}>}
     */
    public function preview(array $cart): array
    {
        $order = new Order([
            'channel' => $cart['channel'],
            'fulfillment_type' => $cart['fulfillment_type'] ?? null,
            'coupon_code' => $cart['coupon_code'] ?? null,
            'customer_id' => $cart['customer_id'] ?? null,
            'status' => 'active',
        ]);

        $items = $cart['items'] instanceof Collection
            ? $cart['items']
            : collect($cart['items']);

        $candidates = $this->eligibleOffers($order, $items, now());
        $auto = $candidates
            ->filter(fn (array $row): bool => $row['source'] === OrderAdjustment::SOURCE_OFFER)
            ->sortByDesc('amount')
            ->first();
        $coupon = $candidates
            ->first(fn (array $row): bool => $row['source'] === OrderAdjustment::SOURCE_COUPON);

        $rows = [];
        if ($auto !== null && ($coupon === null || ($auto['offer']->stackable && $coupon['offer']->stackable))) {
            $rows[] = $this->previewRow($auto);
        }
        if ($coupon !== null) {
            $rows[] = $this->previewRow($coupon);
        }

        return [
            'discount' => round(array_sum(array_column($rows, 'amount')), 2),
            'adjustments' => $rows,
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $lines
     * @return Collection<int, array{offer: Offer, amount: float, source: string, label: string}>
     */
    private function eligibleOffers(Order $order, Collection $lines, CarbonInterface $now): Collection
    {
        $subtotal = round((float) $lines->sum(fn (OrderItem $item): float => (float) $item->subtotal), 2);

        $offers = Offer::query()
            ->where('is_active', true)
            ->get();

        $matches = collect();

        foreach ($offers as $offer) {
            if (! $this->matchesWindow($offer, $now)) {
                continue;
            }

            if (! $this->matchesChannel($offer, (string) $order->channel, $order->fulfillment_type)) {
                continue;
            }

            if ($offer->min_subtotal !== null && $subtotal < (float) $offer->min_subtotal) {
                continue;
            }

            if (! $this->hasRedemptionsLeft($offer, $order)) {
                continue;
            }

            $eligible = $this->eligibleAmount($offer, $lines);
            $amount = $this->discountAmount($offer, $eligible);

            if ($amount <= 0) {
                continue;
            }

            $isCoupon = $offer->isCoupon();
            $code = $order->coupon_code ? strtoupper(trim((string) $order->coupon_code)) : null;

            if ($isCoupon) {
                if ($code === null || strtoupper((string) $offer->code) !== $code) {
                    continue;
                }
            } elseif ($code !== null && strtoupper((string) $offer->code) === $code) {
                continue;
            }

            $matches->push([
                'offer' => $offer,
                'amount' => $amount,
                'source' => $isCoupon ? OrderAdjustment::SOURCE_COUPON : OrderAdjustment::SOURCE_OFFER,
                'label' => $offer->name_ar,
            ]);
        }

        return $matches->values();
    }

    private function matchesWindow(Offer $offer, CarbonInterface $now): bool
    {
        if ($offer->starts_at && $now->lt($offer->starts_at)) {
            return false;
        }

        if ($offer->ends_at && $now->gt($offer->ends_at)) {
            return false;
        }

        $days = $offer->days_of_week ?? [];
        if ($days !== [] && ! in_array($now->dayOfWeek, array_map('intval', $days), true)) {
            return false;
        }

        if ($offer->start_time && $offer->end_time) {
            $current = $now->format('H:i:s');
            $start = substr((string) $offer->start_time, 0, 8);
            $end = substr((string) $offer->end_time, 0, 8);

            if ($start <= $end) {
                if ($current < $start || $current > $end) {
                    return false;
                }
            } elseif ($current < $start && $current > $end) {
                return false;
            }
        }

        return true;
    }

    private function matchesChannel(Offer $offer, string $channel, ?string $fulfillmentType): bool
    {
        $channels = $offer->channels ?? self::DEFAULT_CHANNELS;

        if (! in_array($channel, $channels, true)) {
            return false;
        }

        $fulfillments = $offer->fulfillment_types ?? [];
        if ($fulfillments !== [] && $fulfillmentType !== null) {
            return in_array($fulfillmentType, $fulfillments, true);
        }

        return true;
    }

    private function hasRedemptionsLeft(Offer $offer, Order $order): bool
    {
        if ($offer->max_redemptions !== null) {
            $used = $offer->redemptions()->count();
            if ($used >= (int) $offer->max_redemptions) {
                return false;
            }
        }

        if ($offer->max_per_customer !== null && $order->customer_id) {
            $used = $offer->redemptions()->where('customer_id', $order->customer_id)->count();
            if ($used >= (int) $offer->max_per_customer) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, OrderItem>  $lines
     */
    private function eligibleAmount(Offer $offer, Collection $lines): float
    {
        $filtered = $lines->filter(function (OrderItem $item) use ($offer): bool {
            $type = (string) $offer->target_type;

            if ($type === Offer::TARGET_ORDER) {
                return true;
            }

            if ($type === Offer::TARGET_MENU_ITEM) {
                return (int) $item->menu_item_id === (int) $offer->target_id;
            }

            if ($type === Offer::TARGET_PACKAGE) {
                return (int) $item->package_id === (int) $offer->target_id;
            }

            return $this->lineCategoryId($item) === (int) $offer->target_id;
        });

        return round((float) $filtered->sum(fn (OrderItem $item): float => (float) $item->subtotal), 2);
    }

    private function lineCategoryId(OrderItem $item): ?int
    {
        if ($item->menuItem) {
            return (int) $item->menuItem->category_id;
        }

        if ($item->package instanceof MenuPackage) {
            return (int) $item->package->category_id;
        }

        return null;
    }

    private function discountAmount(Offer $offer, float $eligible): float
    {
        if ($eligible <= 0) {
            return 0.0;
        }

        $amount = $offer->type === Offer::TYPE_PERCENTAGE
            ? round($eligible * ((float) $offer->value / 100), 2)
            : round((float) $offer->value, 2);

        $amount = min($amount, $eligible);

        if ($offer->max_discount !== null) {
            $amount = min($amount, (float) $offer->max_discount);
        }

        return max(0.0, $amount);
    }

    /**
     * @param  array{offer: Offer, amount: float, source: string, label: string}  $row
     */
    private function writeAdjustment(Order $order, array $row): void
    {
        if (! $order->exists) {
            return;
        }

        $order->adjustments()->create([
            'source' => $row['source'],
            'offer_id' => $row['offer']->id,
            'amount' => $row['amount'],
            'label' => $row['label'],
        ]);
    }

    /**
     * @param  array{offer: Offer, amount: float, source: string, label: string}  $row
     * @return array{source: string, offer_id: int, amount: float, label: string}
     */
    private function previewRow(array $row): array
    {
        return [
            'source' => $row['source'],
            'offer_id' => $row['offer']->id,
            'amount' => $row['amount'],
            'label' => $row['label'],
        ];
    }
}
