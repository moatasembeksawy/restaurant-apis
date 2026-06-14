<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = app('tenant');

        $log = Activity::query()
            ->where('log_name', 'pos')
            ->where('properties->tenant_id', $tenant->id)
            ->when($request->query('causer_id'), fn ($q, $id) => $q->where('causer_id', $id))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t))
            ->orderByDesc('created_at')
            ->paginate(50);

        return ApiResponse::success($log);
    }
}
