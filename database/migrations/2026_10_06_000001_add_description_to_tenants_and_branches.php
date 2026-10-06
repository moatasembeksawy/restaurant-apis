<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
