<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Http\Controllers;

use App\Modules\POS\Offline\Http\Requests\HubEventsRequest;
use App\Modules\POS\Offline\Http\Requests\HubMarkItemReadyRequest;
use App\Modules\POS\Offline\Http\Requests\HubPublishRequest;
use App\Modules\POS\Offline\Http\Requests\HubStoreSnapshotRequest;
use App\Modules\POS\Offline\Hub\LocalHubStore;
use App\Modules\POS\Offline\Services\HubCloudSyncService;
use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group Offline POS LAN Hub
 */
class LocalHubController extends Controller
{
    public function __construct(
        private readonly LocalHubStore $store,
        private readonly HubCloudSyncService $cloudSync,
    ) {}

    public function health(): JsonResponse
    {
        return ApiResponse::success($this->store->health(), 'LAN hub is reachable.');
    }

    public function snapshot(): JsonResponse
    {
        $snapshot = $this->store->snapshot();

        if ($snapshot === null) {
            return ApiResponse::error(
                'No catalog snapshot is cached on this hub. Sync from the cloud while online, then POST /hub/snapshot.',
                'HUB_SNAPSHOT_MISSING',
                404,
            );
        }

        return ApiResponse::success($snapshot, 'Cached POS snapshot.');
    }

    public function storeSnapshot(HubStoreSnapshotRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $request->validated('snapshot');
        $this->store->saveSnapshot($snapshot);

        return ApiResponse::success(['saved' => true], 'Hub snapshot cached.');
    }

    public function publish(HubPublishRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $events = $this->store->publish(
                (string) $validated['device_id'],
                (string) $validated['role'],
                $validated['operations'],
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 'HUB_VALIDATION_FAILED', 422);
        }

        return ApiResponse::success([
            'events' => $events,
            'kitchen_queue' => $this->store->kitchenQueue(),
        ], 'LAN hub accepted operations.');
    }

    public function events(HubEventsRequest $request): JsonResponse
    {
        $after = (int) ($request->validated('after') ?? 0);

        return ApiResponse::success([
            'events' => $this->store->eventsAfter($after),
        ]);
    }

    public function kitchenQueue(): JsonResponse
    {
        return ApiResponse::success($this->store->kitchenQueue(), 'LAN kitchen queue.');
    }

    public function markItemReady(HubMarkItemReadyRequest $request, string $clientItemId): JsonResponse
    {
        $validated = $request->validated();

        try {
            $this->store->publish(
                (string) ($validated['device_id'] ?? Str::uuid()),
                'kitchen',
                [[
                    'operation_id' => (string) ($validated['operation_id'] ?? Str::uuid()),
                    'type' => OfflineOperations::ITEM_READY,
                    'occurred_at' => now()->toISOString(),
                    'payload' => [
                        'client_order_id' => $validated['client_order_id'],
                        'client_item_id' => $clientItemId,
                    ],
                ]],
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 'HUB_VALIDATION_FAILED', 422);
        }

        return ApiResponse::success($this->store->kitchenQueue(), 'Item marked ready on the LAN hub.');
    }

    public function cloudSync(): JsonResponse
    {
        try {
            $result = $this->cloudSync->sync();
        } catch (RuntimeException $exception) {
            $status = $exception->getCode() === 502 ? 502 : 422;

            return ApiResponse::error(
                $exception->getMessage(),
                $status === 502 ? 'HUB_CLOUD_SYNC_FAILED' : 'HUB_CLOUD_NOT_CONFIGURED',
                $status,
            );
        }

        return ApiResponse::success($result, 'Hub operations uploaded to the cloud.');
    }
}
