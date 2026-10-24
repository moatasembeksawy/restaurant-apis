<?php

declare(strict_types=1);

use App\Shared\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('phone')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $normalized = PhoneNormalizer::normalize($user->phone);

                    if ($normalized !== $user->phone) {
                        DB::table('users')->where('id', $user->id)->update(['phone' => $normalized]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Normalization is lossy; original formatting cannot be restored.
    }
};
