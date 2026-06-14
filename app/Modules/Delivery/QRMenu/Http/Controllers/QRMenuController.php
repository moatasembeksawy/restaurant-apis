<?php
declare(strict_types=1);
namespace App\Modules\Delivery\QRMenu\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class QRMenuController extends Controller
{
    public function show(string $token): JsonResponse { return ApiResponse::success(['token' => $token, 'menu' => []]); }
    public function placeOrder(Request $request, string $token): JsonResponse { return ApiResponse::created(null, 'Order placed.'); }
}
