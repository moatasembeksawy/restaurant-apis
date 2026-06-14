<?php

declare(strict_types=1);

use App\Modules\Delivery\Aggregators\Http\Controllers\AggregatorWebhookController;
use App\Modules\Delivery\QRMenu\Http\Controllers\QRMenuController;
use App\Modules\Delivery\WhatsApp\Http\Controllers\WhatsAppWebhookController;
use App\Modules\Tenant\Http\Controllers\FawryWebhookController;
use App\Modules\Tenant\Http\Controllers\OnboardingController;
use App\Modules\Tenant\Http\Controllers\PaymobWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // ── Public onboarding ──────────────────────────────────────────────────────
    Route::post('onboarding/register', [OnboardingController::class, 'register']);

    // ── Auth (public + authenticated — handled inside module) ──────────────────
    require base_path('app/Modules/Auth/routes/api.php');

    // ── Platform admin (SaaS operator — no tenant context) ─────────────────────
    Route::prefix('admin')->group(function (): void {
        require base_path('app/Modules/Platform/routes/api.php');
    });

    // ── Authenticated + tenant-resolved routes ─────────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.rate_limit', 'verified.email'])->group(function (): void {

        // Phase 1 — POS
        require base_path('app/Modules/POS/routes/api.php');

        // Phase 2 — Delivery (scaffolded, features behind plan gate)
        require base_path('app/Modules/Delivery/routes/api.php');

        // Phase 3 — Inventory (scaffolded, features behind plan gate)
        require base_path('app/Modules/Inventory/routes/api.php');

        // Phase 4 — Intelligence (scaffolded, features behind plan gate)
        require base_path('app/Modules/Intelligence/routes/api.php');

        // Tenant / Subscription management
        require base_path('app/Modules/Tenant/routes/api.php');
    });

    // ── Public routes (no auth — QR menus, webhook receivers) ─────────────────
    Route::prefix('qr')->group(function (): void {
        Route::get('{token}/menu', [QRMenuController::class, 'show']);
        Route::post('{token}/orders', [QRMenuController::class, 'placeOrder']);
    });

    // ── Inbound webhooks ───────────────────────────────────────────────────────
    Route::prefix('webhook')->group(function (): void {
        Route::any('whatsapp', [WhatsAppWebhookController::class, 'handle']);
        Route::post('paymob', [PaymobWebhookController::class, 'handle']);
        Route::post('fawry', [FawryWebhookController::class, 'handle']);
        Route::post('aggregators/talabat', [AggregatorWebhookController::class, 'talabat']);
        Route::post('aggregators/elmenus', [AggregatorWebhookController::class, 'elmenus']);
    });
});
