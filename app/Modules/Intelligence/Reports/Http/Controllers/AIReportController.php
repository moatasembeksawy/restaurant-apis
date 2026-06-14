<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Reports\Http\Controllers;

use App\Modules\Intelligence\Reports\Http\Requests\WeeklyAIReportRequest;
use App\Modules\Intelligence\Reports\Http\Resources\AIReportResource;
use App\Modules\Intelligence\Reports\Services\AIReportService;
use App\Modules\Intelligence\Reports\Services\LLMNarrativeService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Intelligence — AI Reports
 */
class AIReportController extends Controller
{
    public function __construct(private readonly AIReportService $reports) {}

    public function weekly(WeeklyAIReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $weekStart = isset($validated['week_start'])
            ? Carbon::parse($validated['week_start'])->startOfWeek()
            : null;

        $summary = $this->reports->weeklySummary(
            branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            weekStart: $weekStart,
        );

        if ($request->boolean('narrative')) {
            $summary = app(LLMNarrativeService::class)->enhance($summary);
        }

        return ApiResponse::success(new AIReportResource($summary), 'Weekly AI summary generated.');
    }
}
