<?php
declare(strict_types=1);
namespace App\Modules\Delivery\Customers\Http\Controllers;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()->orderByDesc('last_order_at')->paginate(25);
        return ApiResponse::success($customers);
    }
    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success($customer);
    }
}
