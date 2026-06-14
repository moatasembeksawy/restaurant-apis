<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Marketing\Http\Controllers;

use App\Modules\Intelligence\Marketing\Models\MarketingCampaign;
use App\Modules\Intelligence\Marketing\Services\WhatsAppMarketingService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group WhatsApp Marketing
 */
class MarketingController extends Controller
{
    public function __construct(private readonly WhatsAppMarketingService $marketing) {}

    public function segments(): JsonResponse
    {
        return ApiResponse::success([
            'segments' => $this->marketing->availableSegments(),
            'descriptions' => [
                'all' => 'All customers with a phone number',
                'inactive_30d' => 'No order in the last 30 days',
                'high_spenders' => 'Total spent >= 1000 EGP',
                'recent_visitors' => 'Ordered within the last 7 days',
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $campaigns = MarketingCampaign::query()
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success($campaigns);
    }

    public function broadcast(Request $request): JsonResponse
    {
        $this->authorizeMarketing($request);

        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:100'],
            'segment' => ['required', 'in:all,inactive_30d,high_spenders,recent_visitors'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['string', 'max:255'],
        ]);

        try {
            $campaign = $this->marketing->broadcast(
                tenant: app('tenant'),
                creator: $request->user(),
                templateName: $validated['template_name'],
                segment: $validated['segment'],
                parameters: $validated['parameters'] ?? [],
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'MARKETING_BROADCAST_FAILED', 422);
        }

        return ApiResponse::created($campaign, 'Marketing broadcast queued.');
    }

    private function authorizeMarketing(Request $request): void
    {
        if (! in_array($request->user()->role, ['owner', 'manager'], true)) {
            throw new HttpResponseException(
                ApiResponse::error('Only owners or managers can send marketing broadcasts.', 'FORBIDDEN', 403),
            );
        }
    }
}
