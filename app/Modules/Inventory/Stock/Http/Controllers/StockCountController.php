<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexStockCountRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockCountRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpsertStockCountLineRequest;
use App\Modules\Inventory\Stock\Http\Resources\StockCountResource;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Services\StockCountService;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Stock Counts
 */
class StockCountController extends Controller
{
    public function __construct(private readonly StockCountService $counts) {}

    public function index(IndexStockCountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $counts = StockCount::query()
            ->with(['branch:id,name,name_ar', 'user:id,name'])
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $counts->through(fn (StockCount $count) => $this->counts->format($count)),
            StockCountResource::class,
        );
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branch = Branch::findOrFail($validated['branch_id']);
        $count = $this->counts->start($branch, $request->user(), $validated['notes'] ?? null);

        return ApiResponse::created(new StockCountResource($this->counts->format($count)), 'Stock count started.');
    }

    public function show(StockCount $stockCount): JsonResponse
    {
        return ApiResponse::success(new StockCountResource($this->counts->format($stockCount)));
    }

    public function upsertLine(UpsertStockCountLineRequest $request, StockCount $stockCount): JsonResponse
    {
        $validated = $request->validated();

        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        try {
            $this->counts->upsertLine(
                count: $stockCount,
                ingredient: $ingredient,
                countedQuantity: (float) $validated['counted_quantity'],
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_COUNT_ERROR', 422);
        }

        return ApiResponse::success(
            new StockCountResource($this->counts->format($stockCount->fresh())),
            'Count line saved.',
        );
    }

    public function complete(Request $request, StockCount $stockCount): JsonResponse
    {
        try {
            $count = $this->counts->complete($stockCount, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_COUNT_ERROR', 422);
        }

        return ApiResponse::success(new StockCountResource($this->counts->format($count)), 'Stock count completed.');
    }

    public function cancel(StockCount $stockCount): JsonResponse
    {
        try {
            $count = $this->counts->cancel($stockCount);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_COUNT_ERROR', 422);
        }

        return ApiResponse::success(new StockCountResource($this->counts->format($count)), 'Stock count cancelled.');
    }
}
