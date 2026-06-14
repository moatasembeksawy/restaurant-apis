<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Controllers;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Customers
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->when($request->query('phone'), fn ($q, $phone) => $q->where('phone', 'like', "%{$phone}%"))
            ->when($request->query('search'), fn ($q, $search) => $q->where(function ($q2) use ($search): void {
                $q2->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->orderByDesc('last_order_at')
            ->paginate((int) $request->query('per_page', '25'));

        return ApiResponse::success($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:100'],
            'default_address' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = $this->customers->findOrCreate(
            phone: $validated['phone'],
            name: $validated['name'] ?? null,
            address: $validated['default_address'] ?? null,
        );

        return ApiResponse::created($customer, 'Customer saved.');
    }

    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'default_address' => ['nullable', 'string', 'max:500'],
        ]);

        $customer->update($validated);

        return ApiResponse::success($customer, 'Customer updated.');
    }

    public function orders(Customer $customer): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'payment'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::success($orders);
    }
}
