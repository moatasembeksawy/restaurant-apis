<?php

declare(strict_types=1);

use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\ETA\ETAAdapter;
use App\Shared\Infrastructure\ETA\ETAAdapterInterface;
use App\Shared\Infrastructure\ETA\ETACredentialResolver;
use App\Shared\Infrastructure\ETA\ETACredentials;
use Illuminate\Support\Facades\Http;
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
    expect($credentials->isConfigured())->toBeTrue();
});

it('does not treat global eta env credentials as tenant configuration', function (): void {
    config([
        'services.eta.client_id' => 'global-client',
        'services.eta.client_secret' => 'global-secret',
    ]);

    $tenant = Tenant::factory()->create([
        'eta_client_id' => null,
        'eta_client_secret' => null,
    ]);

    $resolver = app(ETACredentialResolver::class);

    expect($resolver->isConfiguredFor($tenant))->toBeFalse();
    expect($resolver->forTenant($tenant)->clientId)->toBe('');
    expect($resolver->forTenant($tenant)->clientSecret)->toBe('');
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

it('requests an eta access token with basic auth and invoicing scope', function (): void {
    Http::fake([
        'https://id.eta.gov.eg/connect/token' => Http::response([
            'access_token' => 'eta-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'InvoicingAPI',
        ], 200),
    ]);

    $adapter = new ETAAdapter(
        portalUrl: 'https://api.invoicing.eta.gov.eg',
        tokenUrl: 'https://id.eta.gov.eg/connect/token',
    );

    $token = $adapter->getAccessToken(new ETACredentials(
        clientId: 'cid',
        clientSecret: 'csecret',
        taxpayerId: 'tin',
        certPath: 'tenants/1/eta/certificate.pem',
    ));

    expect($token)->toBe('eta-token');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://id.eta.gov.eg/connect/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('cid:csecret'))
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'InvoicingAPI';
    });
});

it('throws when the eta identity service rejects the token request', function (): void {
    Http::fake([
        'https://id.eta.gov.eg/connect/token' => Http::response(['supportID' => '48183559393108303419'], 400),
    ]);

    $adapter = new ETAAdapter(
        portalUrl: 'https://api.invoicing.eta.gov.eg',
        tokenUrl: 'https://id.eta.gov.eg/connect/token',
    );

    $adapter->getAccessToken(new ETACredentials(
        clientId: 'cid',
        clientSecret: 'csecret',
        taxpayerId: 'tin',
    ));
})->throws(RuntimeException::class, 'ETA token request failed: {"supportID":"48183559393108303419"}');
