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
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('fulfillment_type', ['dine_in', 'takeaway', 'delivery'])
                ->default('dine_in')
                ->after('channel');
        });

        DB::table('orders')
            ->whereIn('channel', ['talabat', 'elmenus', 'own_delivery'])
            ->update(['fulfillment_type' => 'delivery']);

        DB::table('orders')
            ->where('channel', 'whatsapp')
            ->whereNotNull('delivery_address')
            ->where('delivery_address', '!=', '')
            ->update(['fulfillment_type' => 'delivery']);

        DB::table('orders')
            ->where('channel', 'whatsapp')
            ->where(function ($query): void {
                $query->whereNull('delivery_address')->orWhere('delivery_address', '=', '');
            })
            ->update(['fulfillment_type' => 'takeaway']);

        DB::table('orders')
            ->where('channel', 'qr')
            ->whereNull('floor_table_id')
            ->update(['fulfillment_type' => 'takeaway']);

        DB::table('orders')
            ->whereNotNull('floor_table_id')
            ->update(['fulfillment_type' => 'dine_in']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('fulfillment_type');
        });
    }
};
