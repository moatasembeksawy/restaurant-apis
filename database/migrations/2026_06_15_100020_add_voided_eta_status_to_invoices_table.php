<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY eta_status ENUM(
            'pending',
            'submitting',
            'accepted',
            'rejected',
            'failed',
            'voided'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY eta_status ENUM(
            'pending',
            'submitting',
            'accepted',
            'rejected',
            'failed'
        ) NOT NULL DEFAULT 'pending'");
    }
};
