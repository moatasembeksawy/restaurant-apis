<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->enum('method', [
                'cash',
                'card',
                'vodafone_cash',
                'instapay',
                'meeza',
                'valu',
            ]);
            $table->decimal('amount', 10, 2);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
    }
};
