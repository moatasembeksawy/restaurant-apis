<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymobWebhookController extends Controller
{
    public function handle(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $hmac = $request->query('hmac')
            ?? $request->header('HMAC')
            ?? $request->input('hmac');

        try {
            $activated = $subscriptions->processPaymobWebhook($request->all(), is_string($hmac) ? $hmac : null);

            return response()->json([
                'status' => $activated ? 'activated' : 'ignored',
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Paymob webhook rejected', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
