<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Services;

use App\Modules\POS\Offline\Hub\LocalHubStore;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HubCloudSyncService
{
    public function __construct(
        private readonly LocalHubStore $store,
    ) {}

    /**
     * @return array{synced: int, pending: int, results: list<array<string, mixed>>}
     */
    public function sync(): array
    {
        $pending = $this->store->pendingCloudOperations();
        if ($pending === []) {
            return ['synced' => 0, 'pending' => 0, 'results' => []];
        }

        $cloudUrl = rtrim((string) config('pos-hub.cloud_url'), '/');
        $cloudToken = (string) config('pos-hub.cloud_token');

        if ($cloudUrl === '' || $cloudToken === '') {
            throw new RuntimeException(
                'POS_HUB_CLOUD_URL and POS_HUB_CLOUD_TOKEN must be set to sync the hub with the cloud.',
                422,
            );
        }

        $headers = [];
        $subdomain = config('pos-hub.cloud_subdomain');
        if (is_string($subdomain) && $subdomain !== '') {
            $headers[(string) config('tenant.subdomain_header', 'X-Tenant-Subdomain')] = $subdomain;
        }

        $response = Http::withToken($cloudToken)
            ->acceptJson()
            ->withHeaders($headers)
            ->post($cloudUrl.'/api/v1/offline/sync', [
                'branch_id' => (int) config('pos-hub.branch_id'),
                'device_id' => $this->store->deviceId(),
                'operations' => $pending,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(__('Cloud sync failed: :reason', ['reason' => $response->body()]), 502);
        }

        $results = $response->json('data.results') ?? [];
        if (! is_array($results)) {
            $results = [];
        }

        $synced = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (in_array($result['disposition'] ?? null, ['applied', 'duplicate'], true)) {
                $synced[] = (string) $result['operation_id'];
            }
        }

        $this->store->markCloudSynced($synced);

        return [
            'synced' => count($synced),
            'pending' => count($pending) - count($synced),
            'results' => array_values($results),
        ];
    }
}
