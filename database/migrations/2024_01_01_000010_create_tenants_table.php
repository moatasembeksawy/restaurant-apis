<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('locale', 5)->default('ar');
            $table->enum('plan', ['starter', 'growth', 'pro', 'enterprise'])->default('starter');
            $table->enum('status', ['active', 'grace_period', 'suspended', 'trial'])->default('trial');
            $table->string('eta_cert_path')->nullable();
            $table->string('kitchen_device_secret')->nullable();
            $table->json('feature_flags')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
