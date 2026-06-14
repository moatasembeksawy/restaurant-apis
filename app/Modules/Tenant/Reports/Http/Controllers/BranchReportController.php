<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Reports\Http\Controllers;

use App\Modules\Tenant\Reports\Services\BranchComparisonService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Multi-branch Reports
 */
class BranchReportController extends Controller
{
    public function __construct(private readonly BranchComparisonService $reports) {}

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $report = $this->reports->compare(
                startDate: isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null,
                endDate: isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'BRANCH_REPORT_FAILED', 422);
        }

        return ApiResponse::success($report);
    }
}
