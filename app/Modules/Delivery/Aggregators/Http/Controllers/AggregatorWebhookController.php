<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Aggregators\Http\Controllers;

use App\Modules\Delivery\Aggregators\Services\AggregatorOrderService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group Aggregator Webhooks
 */
class AggregatorWebhookController extends Controller
{
    public function __construct(private readonly AggregatorOrderService $aggregators) {}

    public function talabat(Request $request): JsonResponse
    {
        return $this->handle($request, 'talabat');
    }

    public function elmenus(Request $request): JsonResponse
    {
        return $this->handle($request, 'elmenus');
    }

    private function handle(Request $request, string $channel): JsonResponse
    {
        $subdomain = (string) $request->header('X-Tenant-Subdomain', '');

        if ($subdomain === '') {
            return ApiResponse::error('X-Tenant-Subdomain header is required.', 'TENANT_REQUIRED', 400);
        }

        $tenant = $this->aggregators->resolveTenant($subdomain);

        if (! $tenant) {
            return ApiResponse::error('Tenant not found.', 'TENANT_NOT_FOUND', 404);
        }

        if (! $tenant->hasFeature('delivery')) {
            return ApiResponse::error('Delivery is not enabled for this tenant.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        $rawPayload = $request->getContent();
        $signature = $request->header('X-Webhook-Signature');

        if (! $this->aggregators->verifySignature($tenant, $channel, $rawPayload, $signature)) {
            return ApiResponse::error('Invalid webhook signature.', 'INVALID_SIGNATURE', 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        try {
            $result = $this->aggregators->ingest($tenant, $channel, $payload);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'AGGREGATOR_ORDER_INVALID', 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'AGGREGATOR_ORDER_FAILED', 422);
        }

        return ApiResponse::success([
            'order_id' => $result['order']->id,
            'external_ref' => $result['order']->external_ref,
            'status' => $result['order']->status,
            'created' => $result['created'],
        ], $result['created'] ? 'Order created.' : 'Order already exists.');
    }
}
