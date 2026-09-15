<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('coupon_code', 50)->nullable()->after('notes');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->enum('line_type', ['item', 'package', 'package_component'])
                ->default('item')
                ->after('order_id');
            $table->foreignId('package_id')
                ->nullable()
                ->after('menu_item_id')
                ->constrained('menu_packages')
                ->nullOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->after('package_id')
                ->constrained('order_items')
                ->cascadeOnDelete();
            $table->json('selections')->nullable()->after('notes');

            $table->index(['order_id', 'parent_id']);
            $table->index(['order_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn(['line_type', 'selections']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('coupon_code');
        });
    }
};
