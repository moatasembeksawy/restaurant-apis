<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Support;

use App\Modules\Delivery\Customers\Models\CustomerAddress;
use App\Modules\Tenant\Districts\Models\District;
use InvalidArgumentException;

final class OrderDeliveryDestination
{
    /**
     * Resolve the delivery address, district, and suggested fee for an order.
     *
     * Incoming keys override current values. Selecting a customer address fills
     * the street address and district (and therefore the suggested fee) unless
     * those fields are also sent. An explicit delivery_fee on the order still
     * wins at the caller.
     *
     * @param  array{
     *     customer_id?: int|null,
     *     customer_address_id?: int|null,
     *     district_id?: int|null,
     *     delivery_address?: string|null
     * }  $incoming
     * @param  array{
     *     customer_id: int|null,
     *     customer_address_id: int|null,
     *     district_id: int|null,
     *     delivery_address: string|null
     * }  $current
     * @return array{
     *     customer_id: int|null,
     *     customer_address_id: int|null,
     *     district_id: int|null,
     *     delivery_address: string|null,
     *     suggested_fee: float|null
     * }
     */
    public static function resolve(array $incoming, array $current): array
    {
        $customerId = array_key_exists('customer_id', $incoming)
            ? self::nullableInt($incoming['customer_id'])
            : $current['customer_id'];
        $customerAddressId = array_key_exists('customer_address_id', $incoming)
            ? self::nullableInt($incoming['customer_address_id'])
            : $current['customer_address_id'];
        $districtId = array_key_exists('district_id', $incoming)
            ? self::nullableInt($incoming['district_id'])
            : $current['district_id'];
        $deliveryAddress = array_key_exists('delivery_address', $incoming)
            ? self::nullableString($incoming['delivery_address'])
            : $current['delivery_address'];

        if ($customerAddressId !== null && array_key_exists('customer_address_id', $incoming)) {
            $address = CustomerAddress::query()->with('district')->find($customerAddressId);

            if ($address === null) {
                throw new InvalidArgumentException('Customer address not found.');
            }

            if ($customerId !== null && (int) $address->customer_id !== $customerId) {
                throw new InvalidArgumentException('Customer address does not belong to this customer.');
            }

            $customerId = (int) $address->customer_id;

            if (! array_key_exists('delivery_address', $incoming)) {
                $deliveryAddress = $address->address;
            }

            if (! array_key_exists('district_id', $incoming)) {
                $districtId = $address->district_id !== null ? (int) $address->district_id : null;
            }
        }

        $district = self::resolveDistrict(
            $districtId,
            newlySelected: array_key_exists('district_id', $incoming)
                || (
                    array_key_exists('customer_address_id', $incoming)
                    && $districtId !== $current['district_id']
                ),
        );

        return [
            'customer_id' => $customerId,
            'customer_address_id' => $customerAddressId,
            'district_id' => $district?->id,
            'delivery_address' => $deliveryAddress,
            'suggested_fee' => $district !== null ? (float) $district->delivery_fee : null,
        ];
    }

    public static function fee(?float $explicitFee, bool $feeProvided, ?float $suggestedFee): ?float
    {
        if ($feeProvided) {
            return $explicitFee;
        }

        return $suggestedFee;
    }

    private static function resolveDistrict(?int $districtId, bool $newlySelected): ?District
    {
        if ($districtId === null) {
            return null;
        }

        $district = District::query()->find($districtId);

        if ($district === null) {
            throw new InvalidArgumentException('District not found.');
        }

        if ($newlySelected && ! $district->is_active) {
            throw new InvalidArgumentException('District is not active.');
        }

        return $district;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
