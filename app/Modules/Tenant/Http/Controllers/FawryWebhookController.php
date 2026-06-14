<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FawryWebhookController extends Controller
{
    public function handle(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        try {
            $activated = $subscriptions->processFawryWebhook($request->all());

            return response()->json([
                'status' => $activated ? 'activated' : 'ignored',
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Fawry webhook rejected', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
