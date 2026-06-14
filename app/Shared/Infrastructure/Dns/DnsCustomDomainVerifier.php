<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dns;

class DnsCustomDomainVerifier implements CustomDomainVerifierInterface
{
    public function cnamePointsTo(string $domain, string $target): bool
    {
        $records = @dns_get_record($domain, DNS_CNAME);

        if (! is_array($records)) {
            return false;
        }

        $expected = strtolower(rtrim($target, '.'));

        foreach ($records as $record) {
            $pointsTo = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));

            if ($pointsTo === $expected) {
                return true;
            }
        }

        return false;
    }

    public function txtRecordMatches(string $host, string $expectedValue): bool
    {
        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $expectedValue) {
                return true;
            }
        }

        return false;
    }
}
