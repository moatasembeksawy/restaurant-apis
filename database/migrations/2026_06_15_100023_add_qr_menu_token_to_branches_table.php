<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('qr_menu_token', 64)->nullable()->unique()->after('is_active');
        });

        DB::table('branches')->whereNull('qr_menu_token')->orderBy('id')->lazy()->each(function (object $branch): void {
            DB::table('branches')
                ->where('id', $branch->id)
                ->update(['qr_menu_token' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['qr_menu_token']);
            $table->dropColumn('qr_menu_token');
        });
    }
};
