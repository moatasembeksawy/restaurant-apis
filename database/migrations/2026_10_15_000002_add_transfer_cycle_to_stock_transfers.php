<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->foreignId('sent_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_id')->nullable()->after('sent_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->after('received_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('notes');
            $table->timestamp('received_at')->nullable()->after('sent_at');
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
        });

        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            $table->decimal('sent_quantity', 12, 4)->nullable()->after('quantity');
            $table->decimal('received_quantity', 12, 4)->nullable()->after('sent_quantity');
            $table->decimal('unit_cost', 10, 4)->nullable()->after('received_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sent_by_id');
            $table->dropConstrainedForeignId('received_by_id');
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['sent_at', 'received_at', 'cancelled_at']);
        });

        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            $table->dropColumn(['sent_quantity', 'received_quantity', 'unit_cost']);
        });
    }
};
