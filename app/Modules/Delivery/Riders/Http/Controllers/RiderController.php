<?php
declare(strict_types=1);
namespace App\Modules\Delivery\Riders\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class RiderController extends Controller
{
    public function index(): JsonResponse { return ApiResponse::success([]); }
    public function assign(Request $request, int $order): JsonResponse { return ApiResponse::success(null, 'Rider assigned.'); }
}
