<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['starter', 'growth', 'pro', 'enterprise']);
            $table->enum('status', ['pending', 'active', 'past_due', 'cancelled'])->default('pending');
            $table->enum('payment_gateway', ['paymob', 'fawry'])->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('gateway', ['paymob', 'fawry']);
            $table->string('gateway_transaction_id')->unique();
            $table->string('merchant_reference');
            $table->enum('plan', ['starter', 'growth', 'pro', 'enterprise']);
            $table->unsignedInteger('amount_cents');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('merchant_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
        Schema::dropIfExists('subscriptions');
    }
};
