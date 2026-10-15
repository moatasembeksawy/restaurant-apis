<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->enum('status', ['draft', 'ordered', 'received', 'completed', 'cancelled'])
                ->default('draft')
                ->change();
            $table->foreignId('received_by_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_id')->nullable()->after('received_by_id')->constrained('users')->nullOnDelete();
            $table->decimal('received_total', 12, 2)->nullable()->after('total');
            $table->timestamp('completed_at')->nullable()->after('received_at');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('received_quantity', 12, 4)->nullable()->after('quantity');
            $table->decimal('received_unit_cost', 10, 4)->nullable()->after('unit_cost');
            $table->decimal('received_subtotal', 12, 2)->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'completed')
            ->update(['status' => 'received']);

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn(['received_quantity', 'received_unit_cost', 'received_subtotal']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by_id');
            $table->dropConstrainedForeignId('completed_by_id');
            $table->dropColumn(['received_total', 'completed_at']);
            $table->enum('status', ['draft', 'ordered', 'received', 'cancelled'])
                ->default('draft')
                ->change();
        });
    }
};
