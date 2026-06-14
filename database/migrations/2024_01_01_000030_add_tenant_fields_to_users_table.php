<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('pin')->nullable()->after('password')
                ->comment('Hashed 4-digit PIN for tablet login');
            $table->enum('role', ['owner', 'manager', 'cashier', 'waiter', 'cook', 'rider'])
                ->default('waiter')->after('pin');
            $table->boolean('is_active')->default(true)->after('role');

            $table->index('tenant_id');
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['tenant_id', 'branch_id', 'phone', 'pin', 'role', 'is_active']);
        });
    }
};
