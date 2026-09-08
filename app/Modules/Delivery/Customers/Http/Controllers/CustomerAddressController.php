<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Controllers;

use App\Modules\Delivery\Customers\Http\Requests\StoreCustomerAddressRequest;
use App\Modules\Delivery\Customers\Http\Requests\UpdateCustomerAddressRequest;
use App\Modules\Delivery\Customers\Http\Resources\CustomerAddressResource;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Models\CustomerAddress;
use App\Modules\Delivery\Customers\Services\CustomerAddressService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Customer Addresses
 */
class CustomerAddressController extends Controller
{
    public function __construct(private readonly CustomerAddressService $addresses) {}

    public function index(Customer $customer): JsonResponse
    {
        $addresses = $customer->addresses()
            ->with('district')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(CustomerAddressResource::collection($addresses));
    }

    public function store(StoreCustomerAddressRequest $request, Customer $customer): JsonResponse
    {
        $address = $this->addresses->create($customer, $request->validated());

        return ApiResponse::created(new CustomerAddressResource($address), 'Address saved.');
    }

    public function update(UpdateCustomerAddressRequest $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
        try {
            $address = $this->addresses->update($customer, $address, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ADDRESS_NOT_FOUND', 404);
        }

        return ApiResponse::success(new CustomerAddressResource($address), 'Address updated.');
    }

    public function destroy(Customer $customer, CustomerAddress $address): Response|JsonResponse
    {
        try {
            $this->addresses->delete($customer, $address);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ADDRESS_NOT_FOUND', 404);
        }

        return ApiResponse::noContent();
    }
}
