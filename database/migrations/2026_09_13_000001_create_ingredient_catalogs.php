<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, int> */
    private array $catalogIds = [];

    public function up(): void
    {
        Schema::create('ingredient_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 40);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->enum('unit', ['kg', 'g', 'l', 'ml', 'piece'])->default('kg');
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'name_ar', 'unit']);
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->foreignId('catalog_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('ingredient_catalogs')
                ->restrictOnDelete();
        });

        $this->backfillCatalogs();

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->unsignedBigInteger('catalog_id')->nullable(false)->change();
            $table->unique(['tenant_id', 'branch_id', 'catalog_id'], 'ingredients_branch_catalog_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropUnique('ingredients_branch_catalog_unique');
            $table->dropConstrainedForeignId('catalog_id');
        });

        Schema::dropIfExists('ingredient_catalogs');
    }

    private function backfillCatalogs(): void
    {
        $assignedAtBranch = [];

        foreach (DB::table('ingredients')->orderBy('id')->get() as $row) {
            $key = $row->tenant_id.'|'.$row->name_ar.'|'.$row->unit;
            $branchKey = $key.'|'.($row->branch_id ?? 'none');

            if (! isset($this->catalogIds[$key])) {
                $this->catalogIds[$key] = $this->insertCatalog($row, $row->name_ar, $row->unit);
            }

            $catalogId = $this->catalogIds[$key];

            if (isset($assignedAtBranch[$branchKey])) {
                $catalogId = $this->insertCatalog($row, $row->name_ar.' #'.$row->id, $row->unit);
            } else {
                $assignedAtBranch[$branchKey] = true;
            }

            DB::table('ingredients')->where('id', $row->id)->update(['catalog_id' => $catalogId]);
        }
    }

    private function insertCatalog(object $row, string $nameAr, string $unit): int
    {
        $sku = 'ING-'.$row->tenant_id.'-'.substr(sha1($nameAr.'|'.$unit.'|'.$row->id), 0, 8);

        return (int) DB::table('ingredient_catalogs')->insertGetId([
            'tenant_id' => $row->tenant_id,
            'sku' => $sku,
            'name_ar' => $nameAr,
            'name_en' => $row->name_en,
            'unit' => $unit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
