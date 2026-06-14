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
            $table->string('eta_client_id')->nullable()->after('eta_cert_path');
            $table->text('eta_client_secret')->nullable()->after('eta_client_id');
            $table->string('eta_taxpayer_id')->nullable()->after('eta_client_secret');
            $table->string('eta_branch_id')->default('0')->after('eta_taxpayer_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['eta_client_id', 'eta_client_secret', 'eta_taxpayer_id', 'eta_branch_id']);
        });
    }
};
