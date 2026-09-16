<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexInventoryStockRequest;
use App\Modules\Inventory\Stock\Http\Requests\LowStockIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreInventoryStockRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateInventoryStockRequest;
use App\Modules\Inventory\Stock\Http\Resources\InventoryStockResource;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Services\IngredientService;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Branch Stock
 */
class InventoryStockController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly IngredientService $ingredients,
    ) {}

    public function index(IndexInventoryStockRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rows = InventoryStock::query()
            ->with('ingredient:id,sku,name_ar,name_en,unit');
        BranchAccess::constrain($rows, $request->user(), $validated['branch_id'] ?? null, 'inventory_stocks.branch_id');

        $rows = $rows
            ->when($validated['ingredient_id'] ?? null, fn ($q, $id) => $q->where('inventory_stocks.ingredient_id', $id))
            ->when($validated['active'] ?? null, fn ($q) => $q->where('inventory_stocks.is_active', true))
            ->orderByName()
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($rows, InventoryStockResource::class);
    }

    public function store(StoreInventoryStockRequest $request): JsonResponse
    {
        try {
            $stock = $this->ingredients->openAtBranch($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INGREDIENT_ERROR', 422);
        }

        return ApiResponse::created(
            new InventoryStockResource($stock->load('ingredient')),
            'Stock opened at branch.',
        );
    }

    public function show(InventoryStock $stock): JsonResponse
    {
        return ApiResponse::success(new InventoryStockResource($stock->load(['branch', 'ingredient'])));
    }

    public function update(UpdateInventoryStockRequest $request, InventoryStock $stock): JsonResponse
    {
        $stock = $this->ingredients->updateStock($stock, $request->validated());

        return ApiResponse::success(new InventoryStockResource($stock), 'Stock updated.');
    }

    public function lowStock(LowStockIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $items = $this->stock->lowStockIngredients(
            branchId: BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null),
        );

        return ApiResponse::success(InventoryStockResource::collection($items));
    }
}
