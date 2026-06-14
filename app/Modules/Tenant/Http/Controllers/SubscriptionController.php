<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\DowngradeSubscriptionRequest;
use App\Modules\Tenant\Http\Requests\UpgradeSubscriptionRequest;
use App\Modules\Tenant\Http\Resources\SubscriptionResource;
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
        return ApiResponse::success(
            new SubscriptionResource($subscriptions->currentPlanDetails(app('tenant'))),
        );
    }

    public function upgrade(UpgradeSubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        $validated = $request->validated();

        try {
            $checkout = $subscriptions->initiateUpgrade(
                tenant: app('tenant'),
                plan: $validated['plan'],
                gateway: $validated['gateway'],
                initiatedBy: $request->user(),
            );

            return ApiResponse::success(new SubscriptionResource($checkout), 'Checkout session created.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_PLAN', 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'CHECKOUT_FAILED', 502);
        }
    }

    public function downgrade(DowngradeSubscriptionRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->scheduleDowngrade(
                tenant: app('tenant'),
                plan: $request->string('plan')->toString(),
                scheduledBy: $request->user(),
            );

            return ApiResponse::success(
                new SubscriptionResource([
                    'pending_plan' => $subscription->pending_plan,
                    'current_period_end' => $subscription->current_period_end?->toISOString(),
                ]),
                'Plan downgrade scheduled for end of billing period.',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SUBSCRIPTION_DOWNGRADE_FAILED', 422);
        }
    }

    public function cancelPendingDowngrade(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->cancelPendingDowngrade(app('tenant'), $request->user());

            return ApiResponse::success(
                new SubscriptionResource([
                    'pending_plan' => null,
                    'plan' => $subscription->plan,
                ]),
                'Pending downgrade cancelled.',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SUBSCRIPTION_DOWNGRADE_CANCEL_FAILED', 422);
        }
    }

    public function cancel(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->cancel(app('tenant'), $request->user());

            return ApiResponse::success(
                new SubscriptionResource([
                    'cancelled_at' => $subscription->cancelled_at?->toISOString(),
                    'current_period_end' => $subscription->current_period_end?->toISOString(),
                ]),
                'Subscription will cancel at the end of the current billing period.',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SUBSCRIPTION_CANCEL_FAILED', 422);
        }
    }

    public function resume(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $subscription = $subscriptions->resume(app('tenant'), $request->user());

            return ApiResponse::success(
                new SubscriptionResource([
                    'cancelled_at' => null,
                    'current_period_end' => $subscription->current_period_end?->toISOString(),
                ]),
                'Subscription cancellation removed.',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SUBSCRIPTION_RESUME_FAILED', 422);
        }
    }
}
