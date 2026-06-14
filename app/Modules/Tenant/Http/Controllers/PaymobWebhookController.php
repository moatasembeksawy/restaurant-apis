<?php
declare(strict_types=1);
namespace App\Modules\Tenant\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class PaymobWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // TODO: verify HMAC, dispatch SubscriptionRenewed event
        return response()->json(['status' => 'received']);
    }
}
