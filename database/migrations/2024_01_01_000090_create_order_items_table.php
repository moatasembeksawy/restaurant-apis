<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name_ar')
                ->comment('Snapshot of name at time of order');
            $table->decimal('unit_price', 10, 2)
                ->comment('Snapshot of price at time of order');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 10, 2);
            $table->enum('status', ['pending', 'cooking', 'ready', 'served', 'cancelled'])
                ->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('cooked_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
