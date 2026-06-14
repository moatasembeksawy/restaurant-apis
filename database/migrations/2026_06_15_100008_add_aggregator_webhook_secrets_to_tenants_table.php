<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('talabat_webhook_secret')->nullable()->after('whatsapp_phone_number_id');
            $table->string('elmenus_webhook_secret')->nullable()->after('talabat_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['talabat_webhook_secret', 'elmenus_webhook_secret']);
        });
    }
};
