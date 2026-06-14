<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Controllers;

use App\Modules\Delivery\Customers\Http\Requests\IndexCustomerRequest;
use App\Modules\Delivery\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Delivery\Customers\Http\Requests\UpdateCustomerRequest;
use App\Modules\Delivery\Customers\Http\Resources\CustomerResource;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\POS\Orders\Http\Resources\OrderResource;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Customers
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(IndexCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $customers = Customer::query()
            ->when($validated['phone'] ?? null, fn ($q, $phone) => $q->where('phone', 'like', "%{$phone}%"))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(function ($q2) use ($search): void {
                $q2->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->orderByDesc('last_order_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return ApiResponse::paginated($customers, CustomerResource::class);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $customer = $this->customers->findOrCreate(
            phone: $validated['phone'],
            name: $validated['name'] ?? null,
            address: $validated['default_address'] ?? null,
        );

        return ApiResponse::created(new CustomerResource($customer), 'Customer saved.');
    }

    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return ApiResponse::success(new CustomerResource($customer), 'Customer updated.');
    }

    public function orders(Customer $customer): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'payment'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::paginated($orders, OrderResource::class);
    }
}
