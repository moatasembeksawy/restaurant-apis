<?php

declare(strict_types=1);

namespace App\Shared\Support\Broadcasting;

use Throwable;

class SafeBroadcast
{
    /**
     * Publish a realtime event without failing the surrounding business action.
     *
     * Kitchen/POS can fall back to REST polling when Reverb is down.
     */
    public static function toOthers(object $event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
