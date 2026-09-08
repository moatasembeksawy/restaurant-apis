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
        if (Schema::hasColumn('districts', 'branch_id')) {
            return;
        }

        Schema::table('districts', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $tenantIds = DB::table('districts')->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $branchId = DB::table('branches')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if ($branchId === null) {
                continue;
            }

            DB::table('districts')
                ->where('tenant_id', $tenantId)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branchId]);
        }

        DB::table('districts')->whereNull('branch_id')->delete();

        Schema::table('districts', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'name']);
        });

        Schema::table('districts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'branch_id', 'name']);
            $table->index(['tenant_id', 'branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('districts', 'branch_id')) {
            return;
        }

        Schema::table('districts', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'branch_id', 'name']);
            $table->dropIndex(['tenant_id', 'branch_id', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
            $table->unique(['tenant_id', 'name']);
        });
    }
};
