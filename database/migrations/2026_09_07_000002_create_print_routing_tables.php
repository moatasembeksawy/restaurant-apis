<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('printing_mode', 16)->default('direct')->after('timezone');
        });

        Schema::create('printers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bridge_key', 100);
            $table->string('type', 16)->default('kitchen');
            $table->unsignedTinyInteger('paper_width')->default(80);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->boolean('auto_print')->default(true);
            $table->boolean('is_default_kitchen')->default(false);
            $table->boolean('is_default_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'bridge_key']);
            $table->index(['tenant_id', 'branch_id', 'type', 'is_active']);
        });

        Schema::create('kitchen_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'name']);
        });

        Schema::create('kitchen_station_printer', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['kitchen_station_id', 'printer_id']);
        });

        Schema::create('print_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_category_id')->nullable()->constrained('menu_categories')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'menu_category_id']);
            $table->index(['tenant_id', 'branch_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_routes');
        Schema::dropIfExists('kitchen_station_printer');
        Schema::dropIfExists('kitchen_stations');
        Schema::dropIfExists('printers');

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('printing_mode');
        });
    }
};
