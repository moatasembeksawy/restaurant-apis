<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu\Http\Controllers;

use App\Modules\Delivery\QRMenu\Services\QRMenuService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group QR Menu (Public)
 *
 * Public endpoints — no authentication required. Tenant is resolved from the table QR token.
 *
 * @unauthenticated
 */
class QRMenuController extends Controller
{
    public function __construct(
        private readonly QRMenuService $qrMenu,
        private readonly \App\Modules\Delivery\Customers\Services\CustomerService $customers,
    ) {}

    /**
     * Get menu for a table
     */
    public function show(string $token): JsonResponse
    {
        try {
            $table = $this->qrMenu->resolveTable($token);

            return ApiResponse::success($this->qrMenu->menuForTable($table));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_QR_TOKEN', 404);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'FEATURE_NOT_AVAILABLE', 402);
        }
    }

    /**
     * Place order from QR menu
     */
    public function placeOrder(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $table = $this->qrMenu->resolveTable($token);

            $order = $this->qrMenu->placeOrder(
                table: $table,
                items: $validated['items'],
                customerName: $validated['customer_name'] ?? null,
                customerPhone: $validated['customer_phone'] ?? null,
                notes: $validated['notes'] ?? null,
            );

            if (! empty($validated['customer_phone'])) {
                $customer = $order->customer;
                if ($customer) {
                    $this->customers->recordOrder($customer, $order);
                }
            }

            return ApiResponse::created([
                'order_id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
                'items' => $order->items,
            ], 'Order placed successfully.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ORDER_ERROR', 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'FEATURE_NOT_AVAILABLE', 402);
        }
    }
}
