<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('photo_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('preparation_time')->default(10);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'is_available']);
        });

        Schema::create('menu_package_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('menu_packages')->cascadeOnDelete();
            $table->enum('type', ['fixed', 'choice']);
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('min_select')->default(1);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('package_id');
        });

        Schema::create('menu_package_slot_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained('menu_package_slots')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->restrictOnDelete();
            $table->decimal('extra_price', 10, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['slot_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_package_slot_options');
        Schema::dropIfExists('menu_package_slots');
        Schema::dropIfExists('menu_packages');
    }
};
