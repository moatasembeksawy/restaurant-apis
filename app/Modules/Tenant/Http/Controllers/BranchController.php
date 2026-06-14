<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\StoreBranchRequest;
use App\Modules\Tenant\Http\Requests\UpdateBranchRequest;
use App\Modules\Tenant\Http\Resources\BranchResource;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Subscription\Exceptions\PlanLimitExceededException;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Branches
 */
class BranchController extends Controller
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->orderBy('name')
            ->get();

        return ApiResponse::success(BranchResource::collection($branches));
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->authorizeBranchManagement($request);

        try {
            $this->planLimits->check('branches');
        } catch (PlanLimitExceededException $e) {
            throw $e;
        }

        $validated = $request->validated();

        $branch = Branch::create([
            ...$validated,
            'timezone' => $validated['timezone'] ?? 'Africa/Cairo',
            'is_default' => Branch::query()->count() === 0,
            'is_active' => true,
        ]);

        AuditLogger::log('branch.created', $branch, ['created_by' => $request->user()->id]);

        return ApiResponse::created(new BranchResource($branch), 'Branch created.');
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranchManagement($request);

        $branch->update($request->validated());

        AuditLogger::log('branch.updated', $branch, ['updated_by' => $request->user()->id]);

        return ApiResponse::success(new BranchResource($branch), 'Branch updated.');
    }

    private function authorizeBranchManagement(Request $request): void
    {
        if (! in_array($request->user()->role, ['owner', 'manager'], true)) {
            throw new HttpResponseException(
                ApiResponse::error('Only owners or managers can manage branches.', 'FORBIDDEN', 403),
            );
        }
    }
}
