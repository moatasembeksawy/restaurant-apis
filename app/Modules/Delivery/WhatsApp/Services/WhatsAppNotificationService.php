<?php

declare(strict_types=1);

namespace App\Modules\Delivery\WhatsApp\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppNotificationService
{
    public function sendOrderConfirmed(Order $order): void
    {
        if (! $this->canNotify($order)) {
            return;
        }

        $this->clientForTenant($order->tenant)->sendTemplate(
            to: $order->customer->phone,
            templateName: config('whatsapp.templates.order_confirmed'),
            languageCode: config('whatsapp.language', 'ar'),
            parameters: [
                $order->customer->name ?? 'Customer',
                (string) $order->id,
                number_format((float) $order->total, 2).' EGP',
            ],
        );
    }

    public function sendOrderReady(Order $order): void
    {
        if (! $this->canNotify($order)) {
            return;
        }

        $this->clientForTenant($order->tenant)->sendTemplate(
            to: $order->customer->phone,
            templateName: config('whatsapp.templates.order_ready'),
            languageCode: config('whatsapp.language', 'ar'),
            parameters: [
                $order->customer->name ?? 'Customer',
                (string) $order->id,
            ],
        );
    }

    public function sendReceipt(Order $order, Payment $payment): void
    {
        if (! $this->canNotify($order)) {
            return;
        }

        $this->clientForTenant($order->tenant)->sendTemplate(
            to: $order->customer->phone,
            templateName: config('whatsapp.templates.receipt'),
            languageCode: config('whatsapp.language', 'ar'),
            parameters: [
                $order->customer->name ?? 'Customer',
                (string) $order->id,
                number_format((float) $payment->amount, 2).' EGP',
                strtoupper($payment->method),
            ],
        );
    }

    public function sendEtaFailureAlert(string $phone, int $orderId, string $error): void
    {
        $tenant = app('tenant');

        if (! $tenant instanceof Tenant || ! $tenant->whatsapp_phone_number_id) {
            return;
        }

        try {
            $this->clientForTenant($tenant)->sendTemplate(
                to: $phone,
                templateName: config('whatsapp.templates.eta_failure'),
                languageCode: config('whatsapp.language', 'ar'),
                parameters: [
                    (string) $orderId,
                    mb_substr($error, 0, 100),
                ],
            );
        } catch (RuntimeException $e) {
            Log::warning('WhatsApp ETA failure alert could not be sent', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendBillingAlert(string $phone, string $event, Tenant $tenant, array $context = []): void
    {
        if (! $tenant->whatsapp_phone_number_id) {
            return;
        }

        $templateName = match ($event) {
            'trial_expired' => config('whatsapp.templates.billing_trial_expired'),
            'grace_expired' => config('whatsapp.templates.billing_grace_expired'),
            'payment_failed' => config('whatsapp.templates.billing_payment_failed'),
            'renewal_reminder' => config('whatsapp.templates.billing_renewal_reminder'),
            'subscription_expired' => config('whatsapp.templates.billing_subscription_expired'),
            default => null,
        };

        if (! $templateName) {
            return;
        }

        try {
            $parameters = match ($event) {
                'trial_expired' => [$tenant->name, (string) $tenant->grace_period_ends_at?->format('Y-m-d')],
                'grace_expired' => [$tenant->name],
                'payment_failed' => [
                    $tenant->name,
                    strtoupper((string) ($context['gateway'] ?? 'unknown')),
                ],
                'renewal_reminder' => [
                    $tenant->name,
                    (string) ($context['period_end'] ?? ''),
                ],
                'subscription_expired' => [$tenant->name],
                default => [$tenant->name],
            };

            $this->clientForTenant($tenant)->sendTemplate(
                to: $phone,
                templateName: $templateName,
                languageCode: config('whatsapp.language', 'ar'),
                parameters: $parameters,
            );
        } catch (RuntimeException $e) {
            Log::warning('WhatsApp billing alert could not be sent', [
                'tenant_id' => $tenant->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function canNotify(Order $order): bool
    {
        $order->loadMissing('customer', 'tenant');

        if (! $order->customer instanceof Customer || ! $order->customer->phone) {
            return false;
        }

        if (! $order->tenant->hasFeature('whatsapp_ordering')) {
            return false;
        }

        if (! $order->tenant->whatsapp_phone_number_id) {
            return false;
        }

        return true;
    }

    private function clientForTenant(Tenant $tenant): WhatsAppClient
    {
        $phoneNumberId = $tenant->whatsapp_phone_number_id;
        $accessToken = config('services.whatsapp.access_token');

        if (! $phoneNumberId || ! $accessToken) {
            throw new RuntimeException('WhatsApp is not configured for this tenant.');
        }

        return new WhatsAppClient(
            phoneNumberId: $phoneNumberId,
            accessToken: $accessToken,
            webhookSecret: (string) config('services.whatsapp.webhook_secret'),
            apiVersion: (string) config('whatsapp.api_version', 'v19.0'),
        );
    }
}
