<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->uuid('client_item_id')->nullable()->after('notes');
            $table->unique(['order_id', 'client_item_id'], 'order_items_order_client_item_unique');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('client_payment_id')->nullable()->after('reference');
            $table->unique(['tenant_id', 'client_payment_id'], 'payments_tenant_client_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_items_order_client_item_unique');
            $table->dropColumn('client_item_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_tenant_client_payment_unique');
            $table->dropColumn('client_payment_id');
        });
    }
};
