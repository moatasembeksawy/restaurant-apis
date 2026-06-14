<?php

declare(strict_types=1);

namespace App\Modules\Delivery\WhatsApp\Http\Controllers;

use App\Modules\Delivery\WhatsApp\Services\WhatsAppOrderService;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClientInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppOrderService $orders,
        private readonly WhatsAppClientInterface $whatsapp,
    ) {}

    public function handle(Request $request): mixed
    {
        if ($request->isMethod('GET')) {
            return $this->verifyChallenge($request);
        }

        $signature = $request->header('X-Hub-Signature-256', '');

        if (config('services.whatsapp.webhook_secret') && $signature !== '') {
            if (! $this->whatsapp->verifyWebhookSignature($request->getContent(), $signature)) {
                Log::warning('WhatsApp webhook signature invalid');

                return response()->json(['status' => 'forbidden'], 403);
            }
        }

        try {
            $this->orders->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook processing failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'received']);
    }

    private function verifyChallenge(Request $request): mixed
    {
        $challenge = $request->query('hub_challenge');
        $token = $request->query('hub_verify_token');

        if ($token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }
}
