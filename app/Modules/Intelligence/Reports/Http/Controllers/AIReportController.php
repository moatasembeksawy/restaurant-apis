<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Reports\Http\Controllers;

use App\Modules\Intelligence\Reports\Services\AIReportService;
use App\Modules\Intelligence\Reports\Services\LLMNarrativeService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Intelligence — AI Reports
 */
class AIReportController extends Controller
{
    public function __construct(private readonly AIReportService $reports) {}

    public function weekly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'week_start' => ['nullable', 'date'],
            'narrative' => ['nullable', 'boolean'],
        ]);

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

        return ApiResponse::success($summary, 'Weekly AI summary generated.');
    }
}
