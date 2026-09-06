<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Controllers;

use App\Modules\Tenant\Finance\Http\Requests\ReverseCashMovementRequest;
use App\Modules\Tenant\Finance\Http\Requests\StoreCashMovementRequest;
use App\Modules\Tenant\Finance\Http\Resources\CashMovementResource;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Finance\Services\CashMovementService;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Shift Cash Movements
 */
class CashMovementController extends Controller
{
    public function __construct(
        private readonly CashMovementService $cashMovements,
    ) {}

    public function index(StaffShift $shift): JsonResponse
    {
        $movements = CashMovement::query()
            ->where('staff_shift_id', $shift->id)
            ->with(['creator:id,name', 'reversedBy:id,name'])
            ->orderBy('occurred_at')
            ->get();

        return ApiResponse::success(CashMovementResource::collection($movements));
    }

    public function store(StoreCashMovementRequest $request, StaffShift $shift): JsonResponse
    {
        $validated = $request->validated();

        try {
            $movement = $this->cashMovements->create(
                shift: $shift,
                user: $request->user(),
                type: $validated['type'],
                amount: (float) $validated['amount'],
                reason: $validated['reason'],
                reference: $validated['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'CASH_MOVEMENT_FAILED', 422);
        }

        return ApiResponse::created(new CashMovementResource($movement), 'Cash movement recorded.');
    }

    public function reverse(ReverseCashMovementRequest $request, CashMovement $movement): JsonResponse
    {
        try {
            $reversal = $this->cashMovements->reverse(
                $movement,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'CASH_MOVEMENT_REVERSAL_FAILED', 422);
        }

        return ApiResponse::created(new CashMovementResource($reversal), 'Cash movement reversed.');
    }
}
