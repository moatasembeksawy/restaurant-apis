<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'clock_in']);
            $table->index(['tenant_id', 'branch_id', 'clock_in']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shifts');
    }
};
