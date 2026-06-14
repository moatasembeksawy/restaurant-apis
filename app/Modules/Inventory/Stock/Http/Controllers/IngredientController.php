<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Stock\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class IngredientController extends Controller
{
    public function index(): JsonResponse { return ApiResponse::success([]); }
    public function store(Request $request): JsonResponse { return ApiResponse::created(null, 'Ingredient created.'); }
    public function update(Request $request, int $ingredient): JsonResponse { return ApiResponse::success(null, 'Updated.'); }
    public function lowStock(): JsonResponse { return ApiResponse::success([]); }
}
