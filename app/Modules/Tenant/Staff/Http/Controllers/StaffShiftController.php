<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Http\Controllers;

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

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->shifts->list(
                branchId: $request->query('branch_id') ? (int) $request->query('branch_id') : null,
                userId: $request->query('user_id') ? (int) $request->query('user_id') : null,
                date: $request->query('date'),
            ),
        );
    }

    public function active(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->shifts->active(
                branchId: $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            ),
        );
    }

    public function clockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $shift = $this->shifts->clockIn(
                user: $request->user(),
                branchId: $validated['branch_id'] ?? null,
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SHIFT_CLOCK_IN_FAILED', 422);
        }

        return ApiResponse::created($shift, 'Shift started.');
    }

    public function clockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $shift = $this->shifts->clockOut(
                user: $request->user(),
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SHIFT_CLOCK_OUT_FAILED', 422);
        }

        return ApiResponse::success($shift, 'Shift ended.');
    }
}
