<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Billing\Http\Requests\RefundPaymentRequest;
use App\Modules\POS\Billing\Http\Requests\SettlePaymentRequest;
use App\Modules\POS\Billing\Http\Resources\PaymentRefundResource;
use App\Modules\POS\Billing\Http\Resources\PaymentSettlementResource;
use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Payments & Billing
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentSettlementService $settlement) {}

    public function settle(SettlePaymentRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->settlement->settle($order, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            $code = match (true) {
                str_contains($e->getMessage(), 'already been paid') => 'ORDER_ALREADY_PAID',
                str_contains($e->getMessage(), 'clock in') => 'NO_ACTIVE_SHIFT',
                default => 'PAYMENT_FAILED',
            };

            return ApiResponse::error($e->getMessage(), $code, 422);
        }

        return ApiResponse::success(new PaymentSettlementResource($result), 'Payment settled successfully.');
    }

    public function refund(RefundPaymentRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->settlement->refund($order, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'already been refunded') ? 'ALREADY_REFUNDED' : 'REFUND_FAILED';

            return ApiResponse::error($e->getMessage(), $code, 422);
        }

        return ApiResponse::success(new PaymentRefundResource([
            'refund' => [
                'id' => $result['refund']->id,
                'amount' => (string) $result['refund']->amount,
                'reason' => $result['refund']->reason,
                'created_at' => $result['refund']->created_at?->toISOString(),
            ],
            'order' => [
                'id' => $result['order']->id,
                'status' => $result['order']->status,
            ],
            'payment' => [
                'id' => $result['payment']->id,
                'refunded_at' => $result['payment']->refunded_at?->toISOString(),
            ],
        ]), 'Payment refunded successfully.');
    }
}
