<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->enum('type', ['fixed', 'choice', 'swap', 'weight']);
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->foreignId('default_menu_item_id')->nullable()->constrained('menu_items')->restrictOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('included_quantity', 12, 4)->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('price_per_unit', 10, 4)->nullable();
            $table->unsignedSmallInteger('min_select')->default(1);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('menu_item_id');
        });

        Schema::create('menu_item_part_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->constrained('menu_item_parts')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->restrictOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->decimal('extra_price', 10, 2)->default(0);
            $table->decimal('price_per_unit', 10, 4)->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('part_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_part_options');
        Schema::dropIfExists('menu_item_parts');
    }
};
