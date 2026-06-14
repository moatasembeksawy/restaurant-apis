<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Controllers;

use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Payments & Billing
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentSettlementService $settlement) {}

    public function settle(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu,split'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'splits' => ['required_if:method,split', 'array', 'min:2'],
            'splits.*.method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.reference' => ['nullable', 'string', 'max:100'],
            'loyalty_points' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->settlement->settle($order, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'already been paid') ? 'ORDER_ALREADY_PAID' : 'PAYMENT_FAILED';

            return ApiResponse::error($e->getMessage(), $code, 422);
        }

        return ApiResponse::success($result, 'Payment settled successfully.');
    }
}
