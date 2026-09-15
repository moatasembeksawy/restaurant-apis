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
        Schema::rename('ingredients', 'inventory_stocks');
        Schema::rename('ingredient_catalogs', 'ingredients');

        $this->dropForeignIfExists('inventory_stocks', 'ingredients_catalog_id_foreign');
        $this->dropForeignIfExists('inventory_stocks', 'inventory_stocks_catalog_id_foreign');
        $this->dropIndexIfExists('inventory_stocks', 'ingredients_branch_catalog_unique');

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->renameColumn('catalog_id', 'ingredient_id');
        });

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->restrictOnDelete();
            $table->unique(['tenant_id', 'branch_id', 'ingredient_id'], 'inventory_stocks_branch_ingredient_unique');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->decimal('default_cost', 10, 4)->default(0)->after('unit');
            $table->boolean('is_active')->default(true)->after('default_cost');
        });

        DB::statement('
            UPDATE ingredients i
            INNER JOIN (
                SELECT ingredient_id, MAX(unit_cost) AS unit_cost
                FROM inventory_stocks
                GROUP BY ingredient_id
            ) s ON s.ingredient_id = i.id
            SET i.default_cost = s.unit_cost
        ');

        $this->repointToMaster('recipes');
        $this->repointToMaster('stock_movements');
        $this->repointToMaster('purchase_order_items');
        $this->repointToMaster('stock_count_lines');

        $this->normalizeTransfers();

        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM(
            'purchase',
            'waste',
            'sale',
            'adjustment',
            'refund',
            'transfer_in',
            'transfer_out',
            'production'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM(
            'purchase',
            'waste',
            'sale',
            'adjustment',
            'refund'
        ) NOT NULL");

        Schema::dropIfExists('stock_transfer_items');

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropColumn('status');
            $table->foreignId('from_ingredient_id')->nullable()->constrained('inventory_stocks')->cascadeOnDelete();
            $table->foreignId('to_ingredient_id')->nullable()->constrained('inventory_stocks')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4)->nullable();
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['default_cost', 'is_active']);
        });

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->dropUnique('inventory_stocks_branch_ingredient_unique');
            $table->dropForeign(['ingredient_id']);
            $table->renameColumn('ingredient_id', 'catalog_id');
        });

        Schema::rename('ingredients', 'ingredient_catalogs');
        Schema::rename('inventory_stocks', 'ingredients');
    }

    private function repointToMaster(string $table): void
    {
        $this->dropForeignIfExists($table, "{$table}_ingredient_id_foreign");

        DB::statement("
            UPDATE {$table} t
            INNER JOIN inventory_stocks s ON s.id = t.ingredient_id
            SET t.ingredient_id = s.ingredient_id
        ");

        if ($table === 'recipes') {
            DB::statement('
                DELETE r1 FROM recipes r1
                INNER JOIN recipes r2
                    ON r1.tenant_id = r2.tenant_id
                    AND r1.menu_item_id = r2.menu_item_id
                    AND r1.ingredient_id = r2.ingredient_id
                    AND r1.id > r2.id
            ');
        }

        if ($table === 'stock_count_lines') {
            DB::statement('
                DELETE l1 FROM stock_count_lines l1
                INNER JOIN stock_count_lines l2
                    ON l1.stock_count_id = l2.stock_count_id
                    AND l1.ingredient_id = l2.ingredient_id
                    AND l1.id > l2.id
            ');
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });
    }

    private function normalizeTransfers(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'in_transit', 'completed', 'cancelled'])
                ->default('completed')
                ->after('to_branch_id');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO stock_transfer_items (stock_transfer_id, ingredient_id, quantity, created_at, updated_at)
            SELECT t.id, s.ingredient_id, t.quantity, t.created_at, t.updated_at
            FROM stock_transfers t
            INNER JOIN inventory_stocks s ON s.id = t.from_ingredient_id
        ');

        $this->dropForeignIfExists('stock_transfers', 'stock_transfers_from_ingredient_id_foreign');
        $this->dropForeignIfExists('stock_transfers', 'stock_transfers_to_ingredient_id_foreign');

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropColumn(['from_ingredient_id', 'to_ingredient_id', 'quantity']);
        });
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'FOREIGN KEY'],
        );

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            [$table, $index],
        );

        if ($exists) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropUnique($index);
            });
        }
    }
};
