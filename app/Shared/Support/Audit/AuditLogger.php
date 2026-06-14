<?php

declare(strict_types=1);

namespace App\Shared\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Record an auditable action with optional subject and tenant context.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $description, ?Model $subject = null, array $properties = []): void
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        $activity = activity('pos')
            ->causedBy(Auth::user())
            ->withProperties([
                'tenant_id' => $tenant?->id,
                ...$properties,
            ]);

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity->log($description);
    }
}
