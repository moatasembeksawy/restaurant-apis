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
            $table->decimal('tax_rate', 5, 2)->default(0)->after('locale');
            $table->decimal('service_charge_rate', 5, 2)->default(0)->after('tax_rate');
            $table->json('service_charge_applies_to')->nullable()->after('service_charge_rate');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('timezone');
            $table->decimal('service_charge_rate', 5, 2)->nullable()->after('tax_rate');
            $table->json('service_charge_applies_to')->nullable()->after('service_charge_rate');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('delivery_fee');
            $table->decimal('tax', 10, 2)->default(0)->after('tax_rate');
            $table->decimal('service_charge_rate', 5, 2)->default(0)->after('tax');
            $table->decimal('service_charge', 10, 2)->default(0)->after('service_charge_rate');
            $table->json('service_charge_applies_to')->nullable()->after('service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_rate',
                'tax',
                'service_charge_rate',
                'service_charge',
                'service_charge_applies_to',
            ]);
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['tax_rate', 'service_charge_rate', 'service_charge_applies_to']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['tax_rate', 'service_charge_rate', 'service_charge_applies_to']);
        });
    }
};
