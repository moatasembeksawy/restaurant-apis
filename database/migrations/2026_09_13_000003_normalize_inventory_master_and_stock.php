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
        if (Schema::hasTable('ingredient_catalogs') && ! Schema::hasTable('inventory_stocks')) {
            Schema::rename('ingredients', 'inventory_stocks');
            Schema::rename('ingredient_catalogs', 'ingredients');
        }

        if (Schema::hasTable('inventory_stocks') && Schema::hasColumn('inventory_stocks', 'catalog_id')) {
            $this->dropForeignIfExists('inventory_stocks', 'ingredients_catalog_id_foreign');
            $this->dropForeignIfExists('inventory_stocks', 'inventory_stocks_catalog_id_foreign');
            $this->dropIndexIfExists('inventory_stocks', 'ingredients_branch_catalog_unique');

            Schema::table('inventory_stocks', function (Blueprint $table): void {
                $table->renameColumn('catalog_id', 'ingredient_id');
            });
        }

        if (Schema::hasTable('inventory_stocks') && Schema::hasColumn('inventory_stocks', 'ingredient_id')) {
            $this->ensureForeign('inventory_stocks', 'ingredient_id', 'ingredients', 'restrict');
            $this->ensureUnique(
                'inventory_stocks',
                ['tenant_id', 'branch_id', 'ingredient_id'],
                'inventory_stocks_branch_ingredient_unique',
            );
        }

        if (Schema::hasTable('ingredients') && ! Schema::hasColumn('ingredients', 'default_cost')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                $table->decimal('default_cost', 10, 4)->default(0)->after('unit');
                $table->boolean('is_active')->default(true)->after('default_cost');
            });
        }

        if (Schema::hasTable('ingredients') && Schema::hasColumn('ingredients', 'default_cost')) {
            DB::statement('
                UPDATE ingredients i
                INNER JOIN (
                    SELECT ingredient_id, MAX(unit_cost) AS unit_cost
                    FROM inventory_stocks
                    GROUP BY ingredient_id
                ) s ON s.ingredient_id = i.id
                SET i.default_cost = s.unit_cost
            ');
        }

        $this->repointToMaster('recipes', 'recipes_tenant_id_menu_item_id_ingredient_id_unique', ['tenant_id', 'menu_item_id', 'ingredient_id']);
        $this->repointToMaster('stock_movements');
        $this->repointToMaster('purchase_order_items');
        $this->repointToMaster('stock_count_lines', 'stock_count_lines_stock_count_id_ingredient_id_unique', ['stock_count_id', 'ingredient_id']);

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

    /**
     * @param  list<string>|null  $uniqueColumns
     */
    private function repointToMaster(string $table, ?string $uniqueIndex = null, ?array $uniqueColumns = null): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'ingredient_id')) {
            return;
        }

        if ($uniqueIndex !== null && $uniqueColumns !== null) {
            foreach ($uniqueColumns as $column) {
                $this->ensureStandaloneIndex($table, $column, $uniqueIndex);
            }
        }

        $this->dropForeignKeysOnColumn($table, 'ingredient_id');

        if ($uniqueIndex !== null) {
            $this->dropIndexIfExists($table, $uniqueIndex);
        }

        if (! $this->ingredientIdsAlreadyPointAtMaster($table)) {
            DB::statement("
                UPDATE {$table} t
                INNER JOIN inventory_stocks s ON s.id = t.ingredient_id
                SET t.ingredient_id = s.ingredient_id
            ");
        }

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

        if ($uniqueIndex !== null && $uniqueColumns !== null) {
            $this->ensureUnique($table, $uniqueColumns, $uniqueIndex);
        }

        $this->ensureForeign($table, 'ingredient_id', 'ingredients', 'cascade');
    }

    private function ingredientIdsAlreadyPointAtMaster(string $table): bool
    {
        return $this->foreignPointsAt($table, 'ingredient_id', 'ingredients');
    }

    private function normalizeTransfers(): void
    {
        if (! Schema::hasTable('stock_transfers')) {
            return;
        }

        if (! Schema::hasColumn('stock_transfers', 'status')) {
            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->enum('status', ['pending', 'in_transit', 'completed', 'cancelled'])
                    ->default('completed')
                    ->after('to_branch_id');
            });
        }

        if (! Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
                $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
                $table->decimal('quantity', 12, 4);
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('stock_transfers', 'from_ingredient_id')) {
            DB::statement('
                INSERT INTO stock_transfer_items (stock_transfer_id, ingredient_id, quantity, created_at, updated_at)
                SELECT t.id, s.ingredient_id, t.quantity, t.created_at, t.updated_at
                FROM stock_transfers t
                INNER JOIN inventory_stocks s ON s.id = t.from_ingredient_id
                WHERE NOT EXISTS (
                    SELECT 1 FROM stock_transfer_items i WHERE i.stock_transfer_id = t.id
                )
            ');

            $this->dropForeignIfExists('stock_transfers', 'stock_transfers_from_ingredient_id_foreign');
            $this->dropForeignIfExists('stock_transfers', 'stock_transfers_to_ingredient_id_foreign');

            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->dropColumn(['from_ingredient_id', 'to_ingredient_id', 'quantity']);
            });
        }
    }

    private function ensureForeign(string $table, string $column, string $referencedTable, string $onDelete): void
    {
        if ($this->foreignPointsAt($table, $column, $referencedTable)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $onDelete): void {
            $foreign = $blueprint->foreign($column)->references('id')->on($referencedTable);

            if ($onDelete === 'restrict') {
                $foreign->restrictOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUnique(string $table, array $columns, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
            $blueprint->unique($columns, $index);
        });
    }

    private function foreignPointsAt(string $table, string $column, string $referencedTable): bool
    {
        $row = DB::selectOne(
            'SELECT REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$table, $column],
        );

        return $row !== null && $row->REFERENCED_TABLE_NAME === $referencedTable;
    }

    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        $rows = DB::select(
            'SELECT DISTINCT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column],
        );

        foreach ($rows as $row) {
            $this->dropForeignIfExists($table, (string) $row->CONSTRAINT_NAME);
        }
    }

    private function ensureStandaloneIndex(string $table, string $column, string $exceptIndex): void
    {
        $existing = DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND SEQ_IN_INDEX = 1
               AND INDEX_NAME != ?
             LIMIT 1',
            [$table, $column, $exceptIndex],
        );

        if ($existing) {
            return;
        }

        $indexName = $table.'_'.$column.'_idx';

        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
            $blueprint->index($column, $indexName);
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
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropUnique($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            [$table, $index],
        ) !== null;
    }
};
