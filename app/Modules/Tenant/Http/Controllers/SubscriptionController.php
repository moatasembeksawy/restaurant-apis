<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function show(SubscriptionService $subscriptions): JsonResponse
    {
        $tenant = app('tenant');

        return ApiResponse::success($subscriptions->currentPlanDetails($tenant));
    }

    public function upgrade(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'manager'])) {
            return ApiResponse::error('Only owners or managers can change subscription plans.', 'FORBIDDEN', 403);
        }

        $validated = $request->validate([
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
            'gateway' => ['required', 'in:paymob,fawry'],
        ]);

        try {
            $checkout = $subscriptions->initiateUpgrade(
                tenant: app('tenant'),
                plan: $validated['plan'],
                gateway: $validated['gateway'],
                initiatedBy: $request->user(),
            );

            return ApiResponse::success($checkout, 'Checkout session created.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PLAN', 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'CHECKOUT_FAILED', 502);
        }
    }
}
