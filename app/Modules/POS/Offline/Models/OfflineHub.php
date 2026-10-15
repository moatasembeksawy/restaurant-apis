<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Models;

use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OfflineHub extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'token_hash',
        'lan_url',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::password(40, symbols: false);
    }
}
