<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Services\StockTransferService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Cross-branch Transfers
 */
class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->transfers->list(
                branchId: $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'ingredient_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

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

        return ApiResponse::created($result, 'Stock transferred successfully.');
    }
}
