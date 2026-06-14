<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('floor_table_id')->nullable()->constrained('floor_tables')->nullOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->enum('channel', ['dine_in', 'qr', 'whatsapp', 'talabat', 'elmenus', 'own_delivery'])
                ->default('dine_in');
            $table->enum('status', [
                'pending',
                'active',
                'cooking',
                'ready',
                'completed',
                'cancelled',
                'paid',
            ])->default('pending');
            $table->string('external_ref')->nullable()
                ->comment('Order ID from Talabat/Elmenus');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('floor_table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
