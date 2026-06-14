<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Services;

use App\Modules\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ETACertificateService
{
    public function store(Tenant $tenant, UploadedFile $certificate): string
    {
        $extension = strtolower($certificate->getClientOriginalExtension() ?: 'pem');

        if (! in_array($extension, ['pem', 'crt', 'cer', 'p12', 'pfx'], true)) {
            throw new InvalidArgumentException('Certificate must be PEM, CRT, CER, P12, or PFX.');
        }

        if ($tenant->eta_cert_path) {
            Storage::disk('local')->delete($tenant->eta_cert_path);
        }

        $path = $certificate->storeAs(
            "tenants/{$tenant->id}/eta",
            "certificate.{$extension}",
            'local',
        );

        $tenant->update(['eta_cert_path' => $path]);

        return $path;
    }

    public function delete(Tenant $tenant): void
    {
        if ($tenant->eta_cert_path) {
            Storage::disk('local')->delete($tenant->eta_cert_path);
        }

        $tenant->update(['eta_cert_path' => null]);
    }
}
