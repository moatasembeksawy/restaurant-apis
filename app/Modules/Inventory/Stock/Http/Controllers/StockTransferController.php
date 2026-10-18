<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\CancelStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Requests\IndexStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Requests\StockTransferLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Resources\StockTransferResource;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Services\StockTransferService;
use App\Shared\Support\Authorization\BranchAccess;
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

    public function index(IndexStockTransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ApiResponse::success(
            StockTransferResource::collection(
                $this->transfers->list(
                    branchId: BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null),
                    status: $validated['status'] ?? null,
                ),
            ),
        );
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $items = $validated['items'] ?? [[
            'ingredient_id' => (int) $validated['ingredient_id'],
            'quantity' => (float) $validated['quantity'],
        ]];

        return $this->respond(
            fn () => $this->transfers->requestTransfer(
                fromBranchId: (int) $validated['from_branch_id'],
                toBranchId: (int) $validated['to_branch_id'],
                items: $items,
                user: $request->user(),
                notes: $validated['notes'] ?? null,
            ),
            'Issue voucher requested.',
            201,
        );
    }

    public function show(StockTransfer $stockTransfer): JsonResponse
    {
        return ApiResponse::success(new StockTransferResource($this->transfers->present($stockTransfer)));
    }

    public function prepare(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->respond(
            fn () => $this->transfers->prepare($stockTransfer, $request->user()),
            'Warehouse accepted the request and is preparing the goods.',
        );
    }

    public function send(StockTransferLinesRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->respond(
            fn () => $this->transfers->send($stockTransfer, $request->user(), $request->validated('items')),
            'Goods dispatched to the restaurant.',
        );
    }

    public function receive(StockTransferLinesRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->respond(
            fn () => $this->transfers->receive($stockTransfer, $request->user(), $request->validated('items')),
            'Goods received.',
        );
    }

    public function cancel(CancelStockTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        return $this->respond(
            fn () => $this->transfers->cancel(
                $stockTransfer,
                $request->user(),
                $request->validated('reason'),
            ),
            'Issue voucher cancelled.',
        );
    }

    private function respond(callable $action, string $message, int $status = 200): JsonResponse
    {
        try {
            $transfer = $action();
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_TRANSFER_FAILED', 422);
        }

        $resource = new StockTransferResource($transfer);

        return $status === 201
            ? ApiResponse::created($resource, $message)
            : ApiResponse::success($resource, $message);
    }
}
