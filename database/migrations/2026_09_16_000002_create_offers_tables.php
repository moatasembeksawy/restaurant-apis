<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->enum('target_type', ['order', 'category', 'menu_item', 'package']);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('code', 50)->nullable();
            $table->json('channels')->nullable();
            $table->json('fulfillment_types')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('days_of_week')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('min_subtotal', 10, 2)->nullable();
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_per_customer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('stackable')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('order_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('source', ['offer', 'coupon', 'loyalty', 'manual']);
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'source']);
        });

        Schema::create('offer_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['offer_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_redemptions');
        Schema::dropIfExists('order_adjustments');
        Schema::dropIfExists('offers');
    }
};
