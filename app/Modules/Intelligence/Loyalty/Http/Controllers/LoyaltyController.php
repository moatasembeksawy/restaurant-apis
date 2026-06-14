<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Loyalty\Http\Controllers;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Services\LoyaltyService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return ApiResponse::success($this->loyalty->profile($customer));
    }

    public function redeem(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->loyalty->redeem(
                $customer,
                (int) $validated['points'],
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'LOYALTY_REDEEM_FAILED', 422);
        }

        return ApiResponse::success([
            'customer_id' => $customer->id,
            'points_redeemed' => $result['points_redeemed'],
            'discount_egp' => $result['discount_egp'],
            'balance' => $result['balance'],
        ], 'Points redeemed successfully.');
    }
}
