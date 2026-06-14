<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('from_ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('to_ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'from_branch_id']);
            $table->index(['tenant_id', 'to_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
