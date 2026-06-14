<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dns;

interface CustomDomainVerifierInterface
{
    public function cnamePointsTo(string $domain, string $target): bool;

    public function txtRecordMatches(string $host, string $expectedValue): bool;
}
