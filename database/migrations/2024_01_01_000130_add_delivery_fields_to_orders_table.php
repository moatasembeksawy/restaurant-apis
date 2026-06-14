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
            $table->foreignId('rider_id')->nullable()->after('customer_id')
                ->constrained('users')->nullOnDelete();
            $table->enum('delivery_status', [
                'pending',
                'assigned',
                'picked_up',
                'en_route',
                'delivered',
                'cancelled',
            ])->nullable()->after('status');
            $table->text('delivery_address')->nullable()->after('notes');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('whatsapp_phone_number_id')->nullable()->unique()->after('kitchen_device_secret');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('default_address')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('default_address');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_phone_number_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn(['delivery_status', 'delivery_address']);
        });
    }
};
