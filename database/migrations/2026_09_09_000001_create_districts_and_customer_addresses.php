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
        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('label')->nullable();
            $table->string('address', 500);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('customer_address_id')->nullable()->after('customer_id')->constrained('customer_addresses')->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('customer_address_id')->constrained('districts')->nullOnDelete();
        });

        $now = now();

        DB::table('customers')
            ->whereNotNull('default_address')
            ->where('default_address', '!=', '')
            ->orderBy('id')
            ->get()
            ->each(function (object $customer) use ($now): void {
                DB::table('customer_addresses')->insert([
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                    'district_id' => null,
                    'label' => null,
                    'address' => $customer->default_address,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_address_id');
            $table->dropConstrainedForeignId('district_id');
        });

        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('districts');
    }
};
