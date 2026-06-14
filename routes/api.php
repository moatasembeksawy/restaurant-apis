<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // ── Auth (public + authenticated — handled inside module) ──────────────────
    require base_path('app/Modules/Auth/routes/api.php');

    // ── Authenticated + tenant-resolved routes ─────────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {

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
        Route::get('{token}/menu', [\App\Modules\Delivery\QRMenu\Http\Controllers\QRMenuController::class, 'show']);
        Route::post('{token}/orders', [\App\Modules\Delivery\QRMenu\Http\Controllers\QRMenuController::class, 'placeOrder']);
    });

    // ── Inbound webhooks ───────────────────────────────────────────────────────
    Route::prefix('webhook')->group(function (): void {
        Route::any('whatsapp', [\App\Modules\Delivery\WhatsApp\Http\Controllers\WhatsAppWebhookController::class, 'handle']);
        Route::post('paymob', [\App\Modules\Tenant\Http\Controllers\PaymobWebhookController::class, 'handle']);
        Route::post('fawry', [\App\Modules\Tenant\Http\Controllers\FawryWebhookController::class, 'handle']);
    });
});
