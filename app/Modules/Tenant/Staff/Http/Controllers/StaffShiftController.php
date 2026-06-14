<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Http\Controllers;

use App\Modules\Tenant\Staff\Http\Requests\ActiveStaffShiftRequest;
use App\Modules\Tenant\Staff\Http\Requests\ClockInStaffShiftRequest;
use App\Modules\Tenant\Staff\Http\Requests\ClockOutStaffShiftRequest;
use App\Modules\Tenant\Staff\Http\Requests\IndexStaffShiftRequest;
use App\Modules\Tenant\Staff\Http\Resources\StaffShiftResource;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Modules\Tenant\Staff\Services\StaffShiftService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Staff Shifts
 */
class StaffShiftController extends Controller
{
    public function __construct(private readonly StaffShiftService $shifts) {}

    public function index(IndexStaffShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            StaffShiftResource::collection(
                $this->shifts->list(
                    branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                    userId: isset($validated['user_id']) ? (int) $validated['user_id'] : null,
                    date: $validated['date'] ?? null,
                ),
            ),
        );
    }

    public function active(ActiveStaffShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            StaffShiftResource::collection(
                $this->shifts->active(
                    branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                ),
            ),
        );
    }

    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->currentFor($request->user());

        if (! $shift) {
            return ApiResponse::error('No active shift found.', 'NO_ACTIVE_SHIFT', 404);
        }

        return ApiResponse::success(new StaffShiftResource($shift));
    }

    public function show(StaffShift $shift): JsonResponse
    {
        return ApiResponse::success(new StaffShiftResource($this->shifts->show($shift)));
    }

    public function clockIn(ClockInStaffShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $shift = $this->shifts->clockIn(
                user: $request->user(),
                branchId: $validated['branch_id'] ?? null,
                notes: $validated['notes'] ?? null,
                openingFloat: isset($validated['opening_float']) ? (float) $validated['opening_float'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SHIFT_CLOCK_IN_FAILED', 422);
        }

        return ApiResponse::created(new StaffShiftResource($shift), 'Shift started.');
    }

    public function clockOut(ClockOutStaffShiftRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $shift = $this->shifts->clockOut(
                user: $request->user(),
                notes: $validated['notes'] ?? null,
                closingCashCount: isset($validated['closing_cash_count'])
                    ? (float) $validated['closing_cash_count']
                    : null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SHIFT_CLOCK_OUT_FAILED', 422);
        }

        return ApiResponse::success(new StaffShiftResource($shift), 'Shift ended.');
    }
}
