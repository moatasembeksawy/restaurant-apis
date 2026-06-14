<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Analytics\Http\Controllers;

use App\Modules\Intelligence\Analytics\Http\Requests\CompareAggregatorRequest;
use App\Modules\Intelligence\Analytics\Http\Resources\AggregatorComparisonResource;
use App\Modules\Intelligence\Analytics\Services\AggregatorAnalyticsService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Intelligence — Aggregator Analytics
 */
class AggregatorController extends Controller
{
    public function __construct(private readonly AggregatorAnalyticsService $analytics) {}

    public function compare(CompareAggregatorRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(new AggregatorComparisonResource(
            $this->analytics->compare(
                branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                startDate: isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null,
                endDate: isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null,
            ),
        ));
    }
}
