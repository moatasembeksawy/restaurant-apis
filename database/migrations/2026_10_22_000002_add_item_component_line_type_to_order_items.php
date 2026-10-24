<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE order_items MODIFY COLUMN line_type ENUM('item', 'package', 'package_component', 'item_component') NOT NULL DEFAULT 'item'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE order_items MODIFY COLUMN line_type ENUM('item', 'package', 'package_component') NOT NULL DEFAULT 'item'");
    }
};
