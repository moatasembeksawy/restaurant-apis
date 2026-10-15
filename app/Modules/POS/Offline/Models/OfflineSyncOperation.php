<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSyncOperation extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'operation_id',
        'device_id',
        'type',
        'client_order_id',
        'result',
        'occurred_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function resultPayload(): array
    {
        $result = $this->getAttribute('result');

        return is_array($result) ? $result : [];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
