<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dns;

class FakeCustomDomainVerifier implements CustomDomainVerifierInterface
{
    /** @var array<string, string> */
    public static array $cnames = [];

    /** @var array<string, string> */
    public static array $txtRecords = [];

    public static function reset(): void
    {
        self::$cnames = [];
        self::$txtRecords = [];
    }

    public function cnamePointsTo(string $domain, string $target): bool
    {
        $expected = strtolower(rtrim($target, '.'));

        return isset(self::$cnames[strtolower($domain)])
            && strtolower(rtrim(self::$cnames[strtolower($domain)], '.')) === $expected;
    }

    public function txtRecordMatches(string $host, string $expectedValue): bool
    {
        return (self::$txtRecords[strtolower($host)] ?? null) === $expectedValue;
    }
}
