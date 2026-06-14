<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('name')->nullable();
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->unsignedInteger('visit_count')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
