<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->decimal('opening_float', 10, 2)->default(0)->after('notes');
            $table->decimal('closing_cash_count', 10, 2)->nullable()->after('opening_float');
            $table->decimal('expected_cash', 10, 2)->nullable()->after('closing_cash_count');
            $table->decimal('cash_variance', 10, 2)->nullable()->after('expected_cash');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('staff_shift_id')
                ->nullable()
                ->after('cashier_id')
                ->constrained('staff_shifts')
                ->nullOnDelete();

            $table->index(['tenant_id', 'staff_shift_id']);
        });

        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->foreignId('staff_shift_id')
                ->nullable()
                ->after('refunded_by')
                ->constrained('staff_shifts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_shift_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_shift_id');
        });

        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->dropColumn([
                'opening_float',
                'closing_cash_count',
                'expected_cash',
                'cash_variance',
            ]);
        });
    }
};
