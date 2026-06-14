<?php
declare(strict_types=1);
namespace App\Modules\Intelligence\Analytics\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
class AggregatorController extends Controller
{
    public function compare(): JsonResponse { return ApiResponse::success([]); }
}
