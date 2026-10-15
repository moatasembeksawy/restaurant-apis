<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Shared\Support\Http\Resources\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePosHub
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('pos-hub.token');
        $provided = (string) $request->header('X-Hub-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return ApiResponse::error('Invalid LAN hub token.', 'HUB_UNAUTHENTICATED', 401);
        }

        return $next($request);
    }
}
