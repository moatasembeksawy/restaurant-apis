<?php

declare(strict_types=1);

use App\Shared\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $duplicates = DB::table('users')
            ->whereNotNull('phone')
            ->select('phone', DB::raw('GROUP_CONCAT(id) as user_ids'))
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates
                ->map(fn ($row): string => "{$row->phone} (users {$row->user_ids})")
                ->implode('; ');

            throw new RuntimeException(
                "Cannot add a unique index on users.phone. Resolve these duplicate phones first: {$details}",
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone_verified_at');
        });
    }
};
