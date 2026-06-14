<?php
declare(strict_types=1);
namespace App\Modules\Intelligence\Loyalty\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class LoyaltyController extends Controller
{
    public function show(int $customer): JsonResponse { return ApiResponse::success(['customer_id' => $customer, 'points' => 0]); }
    public function redeem(Request $request, int $customer): JsonResponse { return ApiResponse::success(null, 'Points redeemed.'); }
}
