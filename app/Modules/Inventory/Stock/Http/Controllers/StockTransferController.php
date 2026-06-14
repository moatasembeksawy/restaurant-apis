<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Resources\StockTransferResource;
use App\Modules\Inventory\Stock\Services\StockTransferService;
use App\Shared\Support\Http\Resources\ApiResponse;
use App\Shared\Support\Http\Resources\DataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Cross-branch Transfers
 */
class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(IndexStockTransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            StockTransferResource::collection(
                $this->transfers->list(
                    branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                ),
            ),
        );
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->transfers->transfer(
                fromBranchId: (int) $validated['from_branch_id'],
                toBranchId: (int) $validated['to_branch_id'],
                ingredientId: (int) $validated['ingredient_id'],
                quantity: (float) $validated['quantity'],
                user: $request->user(),
                notes: $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_TRANSFER_FAILED', 422);
        }

        return ApiResponse::created(new DataResource($result), 'Stock transferred successfully.');
    }
}
