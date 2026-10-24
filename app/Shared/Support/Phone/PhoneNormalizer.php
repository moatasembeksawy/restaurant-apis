<?php

declare(strict_types=1);

namespace App\Shared\Support\Phone;

final class PhoneNormalizer
{
    private const EGYPT_MOBILE = '/^\+201[0125]\d{8}$/';

    private const EGYPT_LANDLINE = '/^\+20[2-9]\d{7,8}$/';

    private const E164 = '/^\+[1-9]\d{7,14}$/';

    /**
     * Normalize to E.164, assuming Egypt for numbers without a country code.
     * 01xxxxxxxxx, 201xxxxxxxxx, 00201xxxxxxxxx and +201xxxxxxxxx all become +201xxxxxxxxx;
     * local landlines such as 02xxxxxxxx become +202xxxxxxxx.
     *
     * Input with no digits is returned trimmed so validation can reject it.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $digits = preg_replace('/\D/', '', $cleaned) ?? '';

        if ($digits === '') {
            return trim($phone);
        }

        if (preg_match('/^(?:0020|20|0)?(1\d{9})$/', $digits, $m) === 1) {
            return '+20'.$m[1];
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+20'.substr($digits, 1);
        }

        return '+'.$digits;
    }

    public static function isValid(?string $phone, bool $mobileOnly = false): bool
    {
        $normalized = self::normalize($phone);

        if ($normalized === null || preg_match(self::E164, $normalized) !== 1) {
            return false;
        }

        if (! str_starts_with($normalized, '+20')) {
            return ! $mobileOnly;
        }

        if (preg_match(self::EGYPT_MOBILE, $normalized) === 1) {
            return true;
        }

        return ! $mobileOnly && preg_match(self::EGYPT_LANDLINE, $normalized) === 1;
    }
}
