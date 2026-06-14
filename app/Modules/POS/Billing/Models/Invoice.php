<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Models;

use App\Shared\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'eta_uuid',
        'eta_qr_url',
        'eta_status',
        'eta_response',
        'pdf_url',
        'retry_count',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'eta_response' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
