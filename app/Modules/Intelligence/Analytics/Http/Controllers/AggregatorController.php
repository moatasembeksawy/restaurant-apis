<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Analytics\Http\Controllers;

use App\Modules\Intelligence\Analytics\Services\AggregatorAnalyticsService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Intelligence — Aggregator Analytics
 */
class AggregatorController extends Controller
{
    public function __construct(private readonly AggregatorAnalyticsService $analytics) {}

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return ApiResponse::success(
            $this->analytics->compare(
                branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                startDate: isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null,
                endDate: isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null,
            ),
        );
    }
}
