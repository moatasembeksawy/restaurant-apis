<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->string('photo_url')->nullable()->after('description_ar');
        });
    }

    public function down(): void
    {
        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->dropColumn('photo_url');
        });
    }
};
