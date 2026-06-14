<?php

declare(strict_types=1);

use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\ETA\ETAAdapter;
use App\Shared\Infrastructure\ETA\ETAAdapterInterface;
use App\Shared\Infrastructure\ETA\ETACredentialResolver;
use App\Shared\Infrastructure\ETA\ETACredentials;
use Illuminate\Support\Facades\Storage;

it('includes uploaded certificate path in eta credentials', function (): void {
    Storage::fake('local');

    $tenant = Tenant::factory()->create([
        'eta_client_id' => 'client-id',
        'eta_client_secret' => 'client-secret',
        'eta_cert_path' => 'tenants/1/eta/certificate.pem',
    ]);

    Storage::disk('local')->put($tenant->eta_cert_path, 'cert-content');

    $credentials = app(ETACredentialResolver::class)->forTenant($tenant);

    expect($credentials->certPath)->toBe('tenants/1/eta/certificate.pem');
    expect($credentials->usesMutualTls())->toBeTrue();
});

it('builds mutual tls options for pem certificates', function (): void {
    Storage::fake('local');

    $path = 'tenants/1/eta/certificate.pem';
    Storage::disk('local')->put($path, 'pem-content');

    $adapter = app(ETAAdapterInterface::class);
    $credentials = new ETACredentials(
        clientId: 'client',
        clientSecret: 'secret',
        taxpayerId: 'tax',
        certPath: $path,
    );

    $reflection = new ReflectionClass(ETAAdapter::class);
    $method = $reflection->getMethod('tlsOptions');
    $method->setAccessible(true);

    $options = $method->invoke($adapter, $credentials);

    expect($options)->toHaveKey('cert');
    expect($options['cert'])->toBe(Storage::disk('local')->path($path));
});
