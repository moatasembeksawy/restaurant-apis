<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Stock\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class StockMovementController extends Controller
{
    public function store(Request $request): JsonResponse { return ApiResponse::created(null, 'Movement recorded.'); }
}
