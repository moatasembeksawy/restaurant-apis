<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Services\TenantManagementService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Platform Admin — Dashboard
 */
class AdminDashboardController extends Controller
{
    public function __construct(private readonly TenantManagementService $tenants) {}

    public function stats(): JsonResponse
    {
        return ApiResponse::success($this->tenants->dashboardStats());
    }
}
