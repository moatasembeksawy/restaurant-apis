<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('catalog_id');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->enum('unit', ['kg', 'g', 'l', 'ml', 'piece'])->nullable()->after('name_en');
        });
    }
};
