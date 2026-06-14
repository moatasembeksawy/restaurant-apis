<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ETA;

readonly class ETACredentials
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $taxpayerId,
        public string $branchId = '0',
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }
}
