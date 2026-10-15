<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Http\Controllers;

use App\Models\User;
use App\Modules\POS\Offline\Http\Requests\HeartbeatOfflineHubRequest;
use App\Modules\POS\Offline\Http\Requests\IndexOfflineHubRequest;
use App\Modules\POS\Offline\Http\Requests\StoreOfflineHubRequest;
use App\Modules\POS\Offline\Models\OfflineHub;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Offline POS LAN Hub
 */
class OfflineHubController extends Controller
{
    public function show(IndexOfflineHubRequest $request): JsonResponse
    {
        $branchId = (int) $request->validated('branch_id');
        /** @var User $user */
        $user = $request->user();
        BranchAccess::assertCanAccess($user, $branchId);

        $hub = OfflineHub::query()->where('branch_id', $branchId)->first();

        return ApiResponse::success($hub ? $this->payload($hub) : null);
    }

    public function store(StoreOfflineHubRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = (int) $validated['branch_id'];
        /** @var User $user */
        $user = $request->user();
        BranchAccess::assertCanAccess($user, $branchId);

        $token = OfflineHub::newToken();
        $hub = OfflineHub::query()->updateOrCreate(
            ['branch_id' => $branchId],
            [
                'name' => $validated['name'] ?? 'Branch hub',
                'token_hash' => OfflineHub::hashToken($token),
                'lan_url' => null,
            ],
        );

        return ApiResponse::created([
            ...$this->payload($hub),
            'token' => $token,
            'port' => (int) config('pos-hub.port'),
            'env' => [
                'POS_HUB_ENABLED' => 'true',
                'POS_HUB_TOKEN' => $token,
                'POS_HUB_BRANCH_ID' => (string) $branchId,
                'POS_HUB_TENANT_ID' => (string) $hub->tenant_id,
                'POS_HUB_PORT' => (string) config('pos-hub.port'),
            ],
        ], 'LAN hub registered. Store the token on the branch mini-PC; it is shown only once.');
    }

    public function heartbeat(HeartbeatOfflineHubRequest $request, OfflineHub $hub): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        BranchAccess::assertCanAccess($user, (int) $hub->branch_id);

        $hub->update([
            'lan_url' => $request->validated('lan_url'),
            'last_seen_at' => now(),
        ]);

        return ApiResponse::success($this->payload($hub->fresh() ?? $hub), 'Hub LAN address saved.');
    }

    /** @return array<string, mixed> */
    private function payload(OfflineHub $hub): array
    {
        return [
            'id' => $hub->id,
            'branch_id' => $hub->branch_id,
            'name' => $hub->name,
            'lan_url' => $hub->lan_url,
            'port' => (int) config('pos-hub.port'),
            'discovery_host' => config('pos-hub.discovery_host'),
            'last_seen_at' => $hub->last_seen_at?->toISOString(),
        ];
    }
}
