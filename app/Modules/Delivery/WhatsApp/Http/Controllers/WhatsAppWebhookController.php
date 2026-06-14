<?php
declare(strict_types=1);
namespace App\Modules\Delivery\WhatsApp\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class WhatsAppWebhookController extends Controller
{
    public function handle(Request $request): mixed
    {
        if ($request->isMethod('GET')) {
            $challenge = $request->query('hub_challenge');
            $token = $request->query('hub_verify_token');
            if ($token === config('services.whatsapp.verify_token')) {
                return response($challenge, 200);
            }
            return response('Forbidden', 403);
        }
        return response()->json(['status' => 'received']);
    }
}
