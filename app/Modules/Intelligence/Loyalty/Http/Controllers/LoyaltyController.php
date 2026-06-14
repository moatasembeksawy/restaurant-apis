<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Loyalty\Http\Controllers;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Http\Requests\RedeemLoyaltyRequest;
use App\Modules\Intelligence\Loyalty\Http\Resources\LoyaltyProfileResource;
use App\Modules\Intelligence\Loyalty\Http\Resources\LoyaltyRedemptionResource;
use App\Modules\Intelligence\Loyalty\Services\LoyaltyService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Intelligence — Loyalty
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success(new LoyaltyProfileResource($this->loyalty->profile($customer)));
    }

    public function redeem(RedeemLoyaltyRequest $request, Customer $customer): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->loyalty->redeem(
                $customer,
                (int) $validated['points'],
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'LOYALTY_REDEEM_FAILED', 422);
        }

        return ApiResponse::success(new LoyaltyRedemptionResource([
            'customer_id' => $customer->id,
            'points_redeemed' => $result['points_redeemed'],
            'discount_egp' => $result['discount_egp'],
            'balance' => $result['balance'],
        ]), 'Points redeemed successfully.');
    }
}
