<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Modules\Tenant\Http\Requests\UpdateETASettingsRequest;
use App\Modules\Tenant\Http\Requests\UploadETACertificateRequest;
use App\Modules\Tenant\Http\Resources\ETASettingsResource;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Services\ETACertificateService;
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
    public function __construct(private readonly ETACertificateService $certificates) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return ApiResponse::success(new ETASettingsResource($this->formatSettings(app('tenant'))));
    }

    public function update(UpdateETASettingsRequest $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validated();
        $tenant = app('tenant');
        $updates = collect($validated)->filter(fn ($v) => $v !== null)->all();

        if ($updates !== []) {
            $tenant->update($updates);
            AuditLogger::log('tenant.eta_settings_updated', $tenant, [
                'updated_by' => $request->user()->id,
                'fields' => array_keys($updates),
            ]);
        }

        return ApiResponse::success(
            new ETASettingsResource($this->formatSettings($tenant->fresh())),
            'ETA settings updated.',
        );
    }

    public function uploadCertificate(UploadETACertificateRequest $request): JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            $this->certificates->store(app('tenant'), $request->file('certificate'));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'ETA_CERTIFICATE_INVALID', 422);
        }

        $tenant = app('tenant')->fresh();

        AuditLogger::log('tenant.eta_certificate_uploaded', $tenant, [
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(
            new ETASettingsResource(['has_certificate' => true]),
            'ETA certificate uploaded.',
        );
    }

    public function deleteCertificate(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $this->certificates->delete(app('tenant'));

        AuditLogger::log('tenant.eta_certificate_deleted', app('tenant'), [
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(
            new ETASettingsResource(['has_certificate' => false]),
            'ETA certificate removed.',
        );
    }

    /** @return array<string, mixed> */
    private function formatSettings(Tenant $tenant): array
    {
        return [
            'eta_client_id' => $tenant->eta_client_id,
            'eta_taxpayer_id' => $tenant->eta_taxpayer_id,
            'eta_branch_id' => $tenant->eta_branch_id,
            'has_certificate' => ! empty($tenant->eta_cert_path),
            'has_client_secret' => ! empty($tenant->eta_client_secret),
            'configured' => ! empty($tenant->eta_client_id) && ! empty($tenant->eta_client_secret),
        ];
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
