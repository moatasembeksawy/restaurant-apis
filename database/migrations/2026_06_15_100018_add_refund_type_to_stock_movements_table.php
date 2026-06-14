<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM(
            'purchase',
            'waste',
            'sale',
            'adjustment',
            'refund'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM(
            'purchase',
            'waste',
            'sale',
            'adjustment'
        ) NOT NULL");
    }
};
