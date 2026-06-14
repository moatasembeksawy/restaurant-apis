<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Recipes\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
class RecipeController extends Controller
{
    public function cost(int $item): JsonResponse { return ApiResponse::success(['item_id' => $item, 'cost' => null, 'note' => 'Recipe not yet configured.']); }
}
