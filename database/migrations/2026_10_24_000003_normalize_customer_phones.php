<?php

declare(strict_types=1);

use App\Shared\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $conflicts = [];

        DB::table('customers')
            ->select(['id', 'tenant_id', 'phone'])
            ->orderBy('id')
            ->chunkById(500, function ($customers) use (&$conflicts): void {
                foreach ($customers as $customer) {
                    $normalized = PhoneNormalizer::normalize($customer->phone);

                    if ($normalized === null || $normalized === $customer->phone) {
                        continue;
                    }

                    $existingId = DB::table('customers')
                        ->where('tenant_id', $customer->tenant_id)
                        ->where('phone', $normalized)
                        ->value('id');

                    // Same person saved twice in different formats; merging orders/loyalty needs a human decision.
                    if ($existingId !== null) {
                        $conflicts[] = "customer {$customer->id} duplicates customer {$existingId} ({$normalized})";

                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['phone' => $normalized]);
                }
            });

        if ($conflicts !== []) {
            Log::warning('Customer phone normalization skipped duplicates; merge them manually.', ['conflicts' => $conflicts]);
        }
    }

    public function down(): void
    {
        // Normalization is lossy; original formatting cannot be restored.
    }
};
