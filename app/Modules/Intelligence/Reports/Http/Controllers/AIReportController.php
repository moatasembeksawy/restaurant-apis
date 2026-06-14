<?php
declare(strict_types=1);
namespace App\Modules\Intelligence\Reports\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
class AIReportController extends Controller
{
    public function weekly(): JsonResponse { return ApiResponse::success(null, 'AI report generation coming in Phase 4.'); }
}
