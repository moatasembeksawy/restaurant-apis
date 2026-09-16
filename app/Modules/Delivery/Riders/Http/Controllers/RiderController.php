<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Controllers;

use App\Models\User;
use App\Modules\Delivery\Riders\Http\Requests\AssignRiderRequest;
use App\Modules\Delivery\Riders\Http\Requests\IndexRiderDeliveryRequest;
use App\Modules\Delivery\Riders\Http\Requests\IndexRiderRequest;
use App\Modules\Delivery\Riders\Http\Requests\IndexUnassignedDeliveryRequest;
use App\Modules\Delivery\Riders\Http\Requests\UpdateDeliveryStatusRequest;
use App\Modules\Delivery\Riders\Http\Resources\RiderResource;
use App\Modules\Delivery\Riders\Services\DeliveryService;
use App\Modules\POS\Orders\Http\Resources\OrderResource;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Riders & Delivery
 */
class RiderController extends Controller
{
    public function __construct(private readonly DeliveryService $delivery) {}

    public function index(IndexRiderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = BranchAccess::filterBranchId($request->user(), $validated['branch_id'] ?? null);

        $riders = User::query()
            ->where('role', 'rider')
            ->where('is_active', true)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->get(['id', 'name', 'phone', 'branch_id']);

        return ApiResponse::success(RiderResource::collection($riders));
    }

    public function assign(AssignRiderRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        $rider = User::query()->findOrFail($validated['rider_id']);

        try {
            $order = $this->delivery->assignRider($order, $rider);

            return ApiResponse::success(new OrderResource($order), 'Rider assigned.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'DELIVERY_ERROR', 422);
        }
    }

    public function updateDeliveryStatus(UpdateDeliveryStatusRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

        try {
            $order = $this->delivery->updateDeliveryStatus($order, $validated['status']);

            return ApiResponse::success(new OrderResource($order), 'Delivery status updated.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INVALID_DELIVERY_TRANSITION', 422);
        }
    }

    /**
     * Active assigned deliveries. Riders see their own jobs; staff see all
     * in-progress deliveries (assigned, picked_up, en_route).
     */
    public function myDeliveries(IndexRiderDeliveryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $orders = Order::query()
            ->whereIn('delivery_status', ['assigned', 'picked_up', 'en_route'])
            ->whereNotNull('rider_id')
            ->whereNotIn('status', ['cancelled', 'completed', 'paid', 'refunded'])
            ->when(
                $user->role === 'rider',
                fn ($q) => $q->where('rider_id', $user->id),
                function ($q) use ($validated, $user) {
                    $q->when($validated['rider_id'] ?? null, fn ($query, $id) => $query->where('rider_id', $id));
                    BranchAccess::constrain($q, $user, $validated['branch_id'] ?? null);
                },
            )
            ->with(['items', 'customer', 'district', 'customerAddress', 'rider', 'payment.splits'])
            ->orderBy('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated($orders, OrderResource::class);
    }

    /**
     * Dispatcher queue of delivery orders that are not yet assigned to a rider.
     */
    public function unassigned(IndexUnassignedDeliveryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orders = Order::query()
            ->where('fulfillment_type', 'delivery')
            ->whereNull('rider_id')
            ->where('delivery_status', 'pending')
            ->whereNotIn('status', ['cancelled', 'completed', 'paid', 'refunded']);
        BranchAccess::constrain($orders, $request->user(), $validated['branch_id'] ?? null);

        $orders = $orders
            ->with(['items', 'customer', 'district', 'customerAddress', 'payment.splits'])
            ->orderBy('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated($orders, OrderResource::class);
    }
}
