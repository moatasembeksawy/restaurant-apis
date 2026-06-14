<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Controllers;

use App\Models\User;
use App\Modules\Delivery\Riders\Services\DeliveryService;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Riders & Delivery
 */
class RiderController extends Controller
{
    public function __construct(private readonly DeliveryService $delivery) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');

        $riders = User::query()
            ->where('role', 'rider')
            ->where('is_active', true)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->get(['id', 'name', 'phone', 'branch_id']);

        return ApiResponse::success($riders);
    }

    public function assign(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $rider = User::query()->findOrFail($validated['rider_id']);

        try {
            $order = $this->delivery->assignRider($order, $rider);

            return ApiResponse::success($order, 'Rider assigned.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'DELIVERY_ERROR', 422);
        }
    }

    public function updateDeliveryStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:assigned,picked_up,en_route,delivered,cancelled'],
        ]);

        try {
            $order = $this->delivery->updateDeliveryStatus($order, $validated['status']);

            return ApiResponse::success($order, 'Delivery status updated.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_DELIVERY_TRANSITION', 422);
        }
    }

    public function myDeliveries(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('rider_id', $request->user()->id)
            ->whereIn('delivery_status', ['assigned', 'picked_up', 'en_route'])
            ->with(['items', 'customer'])
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success($orders);
    }
}
