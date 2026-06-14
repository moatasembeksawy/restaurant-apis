<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\IndexAuditLogRequest;
use App\Modules\Tenant\Http\Resources\AuditLogResource;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenant = app('tenant');

        $log = Activity::query()
            ->where('log_name', 'pos')
            ->where('properties->tenant_id', $tenant->id)
            ->when($validated['causer_id'] ?? null, fn ($q, $id) => $q->where('causer_id', $id))
            ->when($validated['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($log, AuditLogResource::class);
    }
}
