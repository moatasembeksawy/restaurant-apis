<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Infrastructure\Dns\CustomDomainVerifierInterface;
use App\Shared\Infrastructure\Dns\DnsCustomDomainVerifier;
use App\Shared\Infrastructure\Dns\FakeCustomDomainVerifier;
use App\Shared\Infrastructure\ETA\ETAAdapter;
use App\Shared\Infrastructure\ETA\ETAAdapterInterface;
use App\Shared\Infrastructure\Fawry\FawryAdapter;
use App\Shared\Infrastructure\Paymob\PaymobAdapter;
use App\Shared\Infrastructure\PrintJob\EscPosBuilder;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClient;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClientInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ETA adapter — bound by interface for testability
        $this->app->singleton(ETAAdapterInterface::class, fn () => new ETAAdapter(
            portalUrl: config('services.eta.portal_url'),
            tokenUrl: config('services.eta.token_url'),
        ));

        // WhatsApp Meta Business API client
        $this->app->singleton(WhatsAppClientInterface::class, fn () => new WhatsAppClient(
            phoneNumberId: config('services.whatsapp.phone_number_id'),
            accessToken: config('services.whatsapp.access_token'),
            webhookSecret: config('services.whatsapp.webhook_secret'),
            apiVersion: config('services.whatsapp.api_version', 'v19.0'),
        ));

        // Paymob billing adapter
        $this->app->singleton(PaymobAdapter::class, fn () => new PaymobAdapter(
            apiKey: (string) config('services.paymob.api_key'),
            hmacSecret: (string) config('services.paymob.hmac_secret'),
            baseUrl: (string) config('services.paymob.base_url'),
            integrationId: config('services.paymob.integration_id') ? (int) config('services.paymob.integration_id') : null,
            iframeId: config('services.paymob.iframe_id') ? (int) config('services.paymob.iframe_id') : null,
            currency: (string) config('services.paymob.currency', 'EGP'),
        ));

        // Fawry billing adapter
        $this->app->singleton(FawryAdapter::class, fn () => new FawryAdapter(
            merchantCode: (string) config('services.fawry.merchant_code'),
            securityKey: (string) config('services.fawry.security_key'),
            baseUrl: (string) config('services.fawry.base_url'),
        ));

        $this->app->singleton(EscPosBuilder::class, fn () => new EscPosBuilder);

        $this->app->singleton(CustomDomainVerifierInterface::class, function (): CustomDomainVerifierInterface {
            if ($this->app->environment('testing')) {
                return new FakeCustomDomainVerifier;
            }

            return new DnsCustomDomainVerifier;
        });
    }

    public function boot(): void
    {
        // Strict mode in development — catches N+1, lazy loads, mass assignment issues
        Model::shouldBeStrict(! app()->isProduction());

        // Disable wrapping JSON resources in a 'data' key — we handle that in ApiResponse
        JsonResource::withoutWrapping();

        // Register broadcasting channel routes
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/channels.php'));
    }
}
