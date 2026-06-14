<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Controllers;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Print\Http\Resources\PrintJobResource;
use App\Shared\Infrastructure\PrintJob\EscPosBuilder;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Print Jobs
 *
 * Returns raw ESC/POS bytes for frontend print bridges (tablet/USB/network).
 */
class PrintController extends Controller
{
    public function __construct(private readonly EscPosBuilder $escPos) {}

    /**
     * Kitchen ticket bytes for an order.
     */
    public function kitchenTicket(Order $order): JsonResponse
    {
        $order->load(['items', 'table']);
        $branch = $order->branch ?? app('tenant')->defaultBranch;

        if (! $branch) {
            return ApiResponse::error('Branch not found for this order.', 'BRANCH_NOT_FOUND', 422);
        }

        return ApiResponse::success(new PrintJobResource([
            'order_id' => $order->id,
            'format' => 'escpos',
            'encoding' => 'binary',
            'bytes' => base64_encode($this->escPos->buildKitchenTicket($order, $branch)),
        ]));
    }

    /**
     * Customer receipt bytes for a paid order.
     */
    public function receipt(Order $order): JsonResponse
    {
        $payment = $order->payment()->with('invoice')->first();

        if (! $payment) {
            return ApiResponse::error('Order has not been paid yet.', 'ORDER_NOT_PAID', 422);
        }

        $order->load('items');
        $tenant = app('tenant');
        $branch = $order->branch ?? $tenant->defaultBranch;

        if (! $branch) {
            return ApiResponse::error('Branch not found for this order.', 'BRANCH_NOT_FOUND', 422);
        }

        return ApiResponse::success(new PrintJobResource([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'format' => 'escpos',
            'encoding' => 'binary',
            'bytes' => base64_encode($this->escPos->buildReceipt($order, $payment, $tenant, $branch)),
        ]));
    }
}
