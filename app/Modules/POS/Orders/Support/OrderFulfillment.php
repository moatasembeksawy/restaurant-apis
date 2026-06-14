<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Support;

use InvalidArgumentException;

final class OrderFulfillment
{
    public const DINE_IN = 'dine_in';

    public const TAKEAWAY = 'takeaway';

    public const DELIVERY = 'delivery';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::DINE_IN, self::TAKEAWAY, self::DELIVERY];
    }

    public static function resolve(
        string $channel,
        ?int $floorTableId,
        ?string $deliveryAddress,
        ?string $fulfillmentType = null,
    ): string {
        if ($fulfillmentType !== null) {
            return $fulfillmentType;
        }

        if (in_array($channel, ['talabat', 'elmenus', 'own_delivery'], true)) {
            return self::DELIVERY;
        }

        if ($channel === 'whatsapp') {
            return filled($deliveryAddress) ? self::DELIVERY : self::TAKEAWAY;
        }

        if ($floorTableId) {
            return self::DINE_IN;
        }

        if ($channel === 'qr') {
            return self::TAKEAWAY;
        }

        return self::DINE_IN;
    }

    public static function validate(
        string $fulfillmentType,
        string $channel,
        ?int $floorTableId,
        ?string $deliveryAddress,
    ): void {
        if (! in_array($fulfillmentType, self::all(), true)) {
            throw new InvalidArgumentException('Invalid fulfillment type.');
        }

        if (in_array($channel, ['talabat', 'elmenus'], true) && $fulfillmentType !== self::DELIVERY) {
            throw new InvalidArgumentException('Aggregator orders must use delivery fulfillment.');
        }

        if ($fulfillmentType === self::DELIVERY) {
            if (blank($deliveryAddress) && ! in_array($channel, ['talabat', 'elmenus'], true)) {
                throw new InvalidArgumentException('delivery_address is required for delivery orders.');
            }

            if ($floorTableId) {
                throw new InvalidArgumentException('Delivery orders cannot be linked to a table.');
            }
        }

        if ($fulfillmentType === self::TAKEAWAY && $floorTableId) {
            throw new InvalidArgumentException('Takeaway orders cannot be linked to a table.');
        }

        if ($fulfillmentType === self::DINE_IN && $channel === 'qr' && ! $floorTableId) {
            throw new InvalidArgumentException('QR dine-in orders require a table.');
        }
    }

    public static function requiresDeliveryTracking(string $fulfillmentType): bool
    {
        return $fulfillmentType === self::DELIVERY;
    }
}
