<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Http\Controllers;

use App\Models\User;
use App\Modules\POS\Offline\Http\Requests\OfflineBootstrapRequest;
use App\Modules\POS\Offline\Http\Requests\OfflineChangesRequest;
use App\Modules\POS\Offline\Http\Requests\OfflineSyncRequest;
use App\Modules\POS\Offline\Services\OfflineOrderSyncService;
use App\Modules\POS\Offline\Services\OfflineSnapshotService;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * @group Offline POS
 */
class OfflineSyncController extends Controller
{
    public function __construct(
        private readonly OfflineOrderSyncService $sync,
        private readonly OfflineSnapshotService $snapshots,
    ) {}

    public function bootstrap(OfflineBootstrapRequest $request): JsonResponse
    {
        $branchId = (int) $request->validated('branch_id');
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        BranchAccess::assertCanAccess($user, $branchId);

        return ApiResponse::success(
            $this->snapshots->bootstrap($request, $user, $tenant, $branchId),
            'Offline POS snapshot generated.',
        );
    }

    public function changes(OfflineChangesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = (int) $validated['branch_id'];
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        BranchAccess::assertCanAccess($user, $branchId);

        return ApiResponse::success(
            $this->snapshots->changes(
                $request,
                $tenant,
                $branchId,
                CarbonImmutable::parse((string) $validated['cursor']),
            ),
            'Offline POS changes loaded.',
        );
    }

    public function sync(OfflineSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = (int) $validated['branch_id'];
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        BranchAccess::assertCanAccess($user, $branchId);

        $results = $this->sync->sync(
            tenant: $tenant,
            user: $user,
            branchId: $branchId,
            deviceId: (string) $validated['device_id'],
            operations: $validated['operations'],
        );

        return ApiResponse::success([
            'batch_id' => (string) Str::uuid(),
            'processed_at' => now()->toISOString(),
            'results' => $results,
        ], 'Offline operations processed.', meta: [
            'applied' => collect($results)->where('disposition', 'applied')->count(),
            'duplicates' => collect($results)->where('disposition', 'duplicate')->count(),
            'rejected' => collect($results)->where('disposition', 'rejected')->count(),
        ]);
    }
}
