<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Shared\Support\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosHubEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('pos-hub.enabled')) {
            return ApiResponse::error('LAN POS hub is not enabled on this server.', 'HUB_DISABLED', 404);
        }

        return $next($request);
    }
}
