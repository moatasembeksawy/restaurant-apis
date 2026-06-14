<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('eta_uuid')->nullable()->unique()
                ->comment('UUID returned by ETA after successful submission');
            $table->string('eta_qr_url')->nullable()
                ->comment('QR code URL provided by ETA for the invoice');
            $table->enum('eta_status', ['pending', 'submitting', 'accepted', 'rejected', 'failed'])
                ->default('pending');
            $table->json('eta_response')->nullable()
                ->comment('Raw ETA API response for debugging');
            $table->string('pdf_url')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'eta_status']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
