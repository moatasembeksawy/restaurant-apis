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
            $table->enum('status', ['pending', 'preparing', 'in_transit', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
            $table->foreignId('prepared_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable()->after('notes');
            $table->string('cancel_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepared_by_id');
            $table->dropColumn(['prepared_at', 'cancel_reason']);
            $table->enum('status', ['pending', 'in_transit', 'completed', 'cancelled'])
                ->default('completed')
                ->change();
        });
    }
};
