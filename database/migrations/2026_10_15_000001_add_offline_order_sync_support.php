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
            $table->uuid('client_order_id')->nullable()->after('external_ref');
            $table->uuid('source_device_id')->nullable()->after('client_order_id');
            $table->timestamp('offline_created_at')->nullable()->after('source_device_id');

            $table->unique(
                ['tenant_id', 'client_order_id'],
                'orders_tenant_client_order_unique',
            );
            $table->index(
                ['tenant_id', 'source_device_id'],
                'orders_tenant_source_device_index',
            );
        });

        Schema::create('offline_sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('operation_id');
            $table->uuid('device_id');
            $table->string('type', 50);
            $table->uuid('client_order_id')->nullable();
            $table->json('result');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'operation_id'],
                'offline_sync_tenant_operation_unique',
            );
            $table->index(
                ['tenant_id', 'branch_id', 'processed_at'],
                'offline_sync_branch_processed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_operations');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_tenant_client_order_unique');
            $table->dropIndex('orders_tenant_source_device_index');
            $table->dropColumn([
                'client_order_id',
                'source_device_id',
                'offline_created_at',
            ]);
        });
    }
};
