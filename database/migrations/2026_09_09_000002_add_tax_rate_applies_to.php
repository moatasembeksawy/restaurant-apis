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
            $table->json('tax_rate_applies_to')->nullable()->after('tax_rate');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->json('tax_rate_applies_to')->nullable()->after('tax_rate');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->json('tax_rate_applies_to')->nullable()->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_applies_to');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_applies_to');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_applies_to');
        });
    }
};
