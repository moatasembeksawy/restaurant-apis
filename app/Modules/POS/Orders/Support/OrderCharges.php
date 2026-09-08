<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Support;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

final class OrderCharges
{
    /** @var list<string> */
    public const DEFAULT_SERVICE_CHARGE_APPLIES_TO = [OrderFulfillment::DINE_IN];

    /**
     * Branch values override tenant defaults. Null on the branch means inherit.
     *
     * @return array{tax_rate: float, service_charge_rate: float, service_charge_applies_to: list<string>}
     */
    public static function resolve(?Tenant $tenant, ?Branch $branch): array
    {
        return [
            'tax_rate' => self::rate($branch?->tax_rate, $tenant?->tax_rate),
            'service_charge_rate' => self::rate($branch?->service_charge_rate, $tenant?->service_charge_rate),
            'service_charge_applies_to' => self::appliesTo(
                $branch?->service_charge_applies_to,
                $tenant?->service_charge_applies_to,
            ),
        ];
    }

    /**
     * @return array{service_charge: float, tax: float, total: float}
     */
    public static function compute(Order $order): array
    {
        $net = max(0, (float) $order->subtotal - (float) $order->discount);
        $appliesTo = self::appliesTo($order->service_charge_applies_to, null);

        $serviceCharge = 0.0;
        if ((float) $order->service_charge_rate > 0 && in_array($order->fulfillment_type, $appliesTo, true)) {
            $serviceCharge = round($net * ((float) $order->service_charge_rate / 100), 2);
        }

        $tax = round(($net + $serviceCharge) * ((float) $order->tax_rate / 100), 2);

        return [
            'service_charge' => $serviceCharge,
            'tax' => $tax,
            'total' => $net + $serviceCharge + $tax + (float) $order->delivery_fee,
        ];
    }

    private static function rate(mixed $branchRate, mixed $tenantRate): float
    {
        if ($branchRate !== null) {
            return round((float) $branchRate, 2);
        }

        return round((float) ($tenantRate ?? 0), 2);
    }

    /** @return list<string> */
    private static function appliesTo(mixed $branchAppliesTo, mixed $tenantAppliesTo): array
    {
        $source = $branchAppliesTo ?? $tenantAppliesTo ?? self::DEFAULT_SERVICE_CHARGE_APPLIES_TO;

        if (! is_array($source)) {
            return self::DEFAULT_SERVICE_CHARGE_APPLIES_TO;
        }

        /** @var list<string> $filtered */
        $filtered = array_values(array_intersect($source, OrderFulfillment::all()));

        return $filtered;
    }
}
