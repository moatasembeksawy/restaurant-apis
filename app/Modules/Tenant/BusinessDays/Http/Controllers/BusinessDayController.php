<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Stock\Http\Resources\StockTransferResource;
use App\Modules\Tenant\BusinessDays\Http\Requests\CloseBusinessDayRequest;
use App\Modules\Tenant\BusinessDays\Http\Requests\IndexBusinessDayRequest;
use App\Modules\Tenant\BusinessDays\Http\Requests\OpenBusinessDayRequest;
use App\Modules\Tenant\BusinessDays\Http\Requests\RecordBusinessDayWasteRequest;
use App\Modules\Tenant\BusinessDays\Http\Requests\ReturnBusinessDayStockRequest;
use App\Modules\Tenant\BusinessDays\Http\Resources\BusinessDayResource;
use App\Modules\Tenant\BusinessDays\Models\BusinessDay;
use App\Modules\Tenant\BusinessDays\Services\BusinessDayService;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use App\Shared\Support\Http\Resources\DataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Business Days
 */
class BusinessDayController extends Controller
{
    public function __construct(private readonly BusinessDayService $days) {}

    public function index(IndexBusinessDayRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(BusinessDayResource::collection(
            $this->days->list(
                branchId: BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null),
                status: $validated['status'] ?? null,
                date: $validated['date'] ?? null,
            ),
        ));
    }

    public function current(Request $request): JsonResponse
    {
        try {
            $branchId = $this->branchId($request);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'BUSINESS_DAY_BRANCH_REQUIRED', 422);
        }

        $day = $this->days->current($branchId);

        if (! $day) {
            return ApiResponse::error('No open business day found.', 'NO_OPEN_BUSINESS_DAY', 404);
        }

        return ApiResponse::success(new BusinessDayResource($day));
    }

    public function show(BusinessDay $businessDay, Request $request): JsonResponse
    {
        BranchAccess::assertCanAccess($request->user(), (int) $businessDay->branch_id);

        return ApiResponse::success(new BusinessDayResource(
            $businessDay->load(['branch:id,name', 'openedBy:id,name', 'closedBy:id,name']),
        ));
    }

    public function open(OpenBusinessDayRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $day = $this->days->open(
                branchId: $this->branchId($request, $validated['branch_id'] ?? null),
                user: $request->user(),
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'BUSINESS_DAY_OPEN_FAILED', 422);
        }

        return ApiResponse::created(new BusinessDayResource($day), 'Business day opened.');
    }

    public function close(
        CloseBusinessDayRequest $request,
        BusinessDay $businessDay,
    ): JsonResponse {
        try {
            $day = $this->days->close(
                day: $businessDay,
                user: $request->user(),
                notes: $request->validated('notes'),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'BUSINESS_DAY_CLOSE_FAILED', 422);
        }

        return ApiResponse::success(new BusinessDayResource($day), 'Business day closed.');
    }

    public function report(BusinessDay $businessDay, Request $request): JsonResponse
    {
        BranchAccess::assertCanAccess($request->user(), (int) $businessDay->branch_id);

        return ApiResponse::success(new DataResource($this->days->report($businessDay)));
    }

    public function remainingStock(BusinessDay $businessDay, Request $request): JsonResponse
    {
        BranchAccess::assertCanAccess($request->user(), (int) $businessDay->branch_id);

        return ApiResponse::success(new DataResource([
            'business_day_id' => $businessDay->id,
            'status' => $businessDay->status,
            'items' => $this->days->remainingStock($businessDay),
        ]));
    }

    public function waste(
        RecordBusinessDayWasteRequest $request,
        BusinessDay $businessDay,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $movement = $this->days->recordWaste(
                day: $businessDay,
                user: $request->user(),
                ingredientId: (int) $validated['ingredient_id'],
                quantity: (float) $validated['quantity'],
                reason: $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_ERROR', 422);
        }

        return ApiResponse::created(new StockMovementResource($movement), 'Waste recorded.');
    }

    public function returnStock(
        ReturnBusinessDayStockRequest $request,
        BusinessDay $businessDay,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $transfer = $this->days->returnStock(
                day: $businessDay,
                user: $request->user(),
                toBranchId: isset($validated['to_branch_id']) ? (int) $validated['to_branch_id'] : null,
                items: $validated['items'] ?? null,
                returnRemaining: (bool) ($validated['return_remaining'] ?? false),
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_RETURN_FAILED', 422);
        }

        return ApiResponse::created(new StockTransferResource($transfer), 'Stock return sent to main inventory.');
    }

    private function branchId(Request $request, mixed $requested = null): int
    {
        $branchId = BranchAccess::filterBranchId(
            $request->user(),
            $requested ?? $request->query('branch_id') ?? $request->user()?->branch_id,
        );

        if (! $branchId) {
            throw new InvalidArgumentException('branch_id is required.');
        }

        return (int) $branchId;
    }
}
