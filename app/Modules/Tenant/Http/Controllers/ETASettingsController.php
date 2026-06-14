<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Tenant Settings
 */
class ETASettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $tenant = app('tenant');

        return ApiResponse::success([
            'eta_client_id' => $tenant->eta_client_id,
            'eta_taxpayer_id' => $tenant->eta_taxpayer_id,
            'eta_branch_id' => $tenant->eta_branch_id,
            'eta_cert_path' => $tenant->eta_cert_path,
            'has_client_secret' => ! empty($tenant->eta_client_secret),
            'configured' => ! empty($tenant->eta_client_id) && ! empty($tenant->eta_client_secret),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'eta_client_id' => ['nullable', 'string', 'max:100'],
            'eta_client_secret' => ['nullable', 'string', 'max:255'],
            'eta_taxpayer_id' => ['nullable', 'string', 'max:100'],
            'eta_branch_id' => ['nullable', 'string', 'max:20'],
            'eta_cert_path' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = app('tenant');
        $updates = collect($validated)->filter(fn ($v) => $v !== null)->all();

        if ($updates !== []) {
            $tenant->update($updates);
            AuditLogger::log('tenant.eta_settings_updated', $tenant, [
                'updated_by' => $request->user()->id,
                'fields' => array_keys($updates),
            ]);
        }

        return ApiResponse::success([
            'eta_client_id' => $tenant->fresh()->eta_client_id,
            'eta_taxpayer_id' => $tenant->fresh()->eta_taxpayer_id,
            'eta_branch_id' => $tenant->fresh()->eta_branch_id,
            'has_client_secret' => ! empty($tenant->fresh()->eta_client_secret),
            'configured' => ! empty($tenant->fresh()->eta_client_id) && ! empty($tenant->fresh()->eta_client_secret),
        ], 'ETA settings updated.');
    }

    private function authorizeOwner(Request $request): void
    {
        if ($request->user()->role !== 'owner') {
            throw new HttpResponseException(
                ApiResponse::error('Only the owner can manage ETA settings.', 'FORBIDDEN', 403),
            );
        }
    }
}
