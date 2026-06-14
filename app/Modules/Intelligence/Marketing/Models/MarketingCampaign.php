<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Marketing\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaign extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'created_by',
        'template_name',
        'segment',
        'recipients_count',
        'sent_count',
        'failed_count',
        'status',
        'parameters',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
