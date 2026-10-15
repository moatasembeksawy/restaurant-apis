<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('open');
            $table->string('active_key')->nullable()->unique();
            $table->unsignedBigInteger('next_order_number')->default(1);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->json('opening_stock')->nullable();
            $table->json('closing_summary')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'business_date']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->foreignId('business_day_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('business_days')
                ->nullOnDelete();
            $table->index(['business_day_id', 'clock_in']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('business_day_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('business_days')
                ->nullOnDelete();
            $table->foreignId('staff_shift_id')
                ->nullable()
                ->after('waiter_id')
                ->constrained('staff_shifts')
                ->nullOnDelete();
            $table->unsignedBigInteger('business_day_order_number')->nullable()->after('business_day_id');
            $table->unique(
                ['business_day_id', 'business_day_order_number'],
                'orders_business_day_number_unique',
            );
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('business_day_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('business_days')
                ->nullOnDelete();
            $table->index(['business_day_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['business_day_id', 'type']);
            $table->dropConstrainedForeignId('business_day_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_business_day_number_unique');
            $table->dropConstrainedForeignId('staff_shift_id');
            $table->dropConstrainedForeignId('business_day_id');
        });

        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->dropIndex(['business_day_id', 'clock_in']);
            $table->dropConstrainedForeignId('business_day_id');
        });

        Schema::dropIfExists('business_days');
    }
};
