<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu\Http\Controllers;

use App\Modules\Delivery\QRMenu\Http\Requests\PlaceQRMenuOrderRequest;
use App\Modules\Delivery\QRMenu\Http\Resources\QRMenuOrderResource;
use App\Modules\Delivery\QRMenu\Http\Resources\QRMenuResource;
use App\Modules\Delivery\QRMenu\Services\QRMenuService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group QR Menu (Public)
 *
 * Public endpoints — no authentication required.
 * Token may be a branch menu link (social/print) or a table QR (dine-in).
 *
 * @unauthenticated
 */
class QRMenuController extends Controller
{
    public function __construct(private readonly QRMenuService $qrMenu) {}

    /**
     * Get public menu
     */
    public function show(string $token): JsonResponse
    {
        try {
            $context = $this->qrMenu->resolve($token);

            return ApiResponse::success(new QRMenuResource($this->qrMenu->menuFor($context)));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_QR_TOKEN', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'FEATURE_NOT_AVAILABLE', 402);
        }
    }

    /**
     * Place order from public QR menu
     */
    public function placeOrder(PlaceQRMenuOrderRequest $request, string $token): JsonResponse
    {
        $validated = $request->validated();

        try {
            $context = $this->qrMenu->resolve($token);

            $order = $this->qrMenu->placeOrder(
                context: $context,
                items: $validated['items'],
                customerName: $validated['customer_name'] ?? null,
                customerPhone: $validated['customer_phone'] ?? null,
                notes: $validated['notes'] ?? null,
                tableLabel: $validated['table_label'] ?? null,
                fulfillmentType: $validated['fulfillment_type'] ?? null,
                deliveryAddress: $validated['delivery_address'] ?? null,
            );

            return ApiResponse::created(new QRMenuOrderResource([
                'order_id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
                'fulfillment_type' => $order->fulfillment_type,
                'items' => $order->items,
                'source' => $context->isTableLinked() ? 'table' : 'branch',
            ]), 'Order placed successfully.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ORDER_ERROR', 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'FEATURE_NOT_AVAILABLE', 402);
        }
    }
}
