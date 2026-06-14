<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('method', [
                'cash',
                'card',
                'vodafone_cash',
                'instapay',
                'meeza',
                'valu',
                'split',
            ])->default('cash');
            $table->decimal('amount', 10, 2);
            $table->decimal('cash_tendered', 10, 2)->nullable()
                ->comment('For cash payments: amount given by customer');
            $table->decimal('change_due', 10, 2)->nullable();
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->string('discount_reason')->nullable();
            $table->string('reference')->nullable()
                ->comment('External payment reference/receipt number');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
