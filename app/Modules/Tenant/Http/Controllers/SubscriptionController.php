<?php
declare(strict_types=1);
namespace App\Modules\Tenant\Http\Controllers;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class SubscriptionController extends Controller
{
    public function show(): JsonResponse
    {
        $tenant = app('tenant');
        return ApiResponse::success(['plan' => $tenant->plan, 'status' => $tenant->status, 'limits' => $tenant->planLimits()]);
    }
    public function upgrade(Request $request): JsonResponse
    {
        $request->validate(['plan' => ['required', 'in:starter,growth,pro,enterprise']]);
        return ApiResponse::success(null, 'Upgrade initiated. You will be redirected to payment.');
    }
}
