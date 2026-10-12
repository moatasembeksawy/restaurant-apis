<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Providers\TelescopeServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeTelescope
{
    public function handle(Request $request, Closure $next): Response
    {
        if (TelescopeServiceProvider::allows($request)) {
            return $next($request);
        }

        return response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="Telescope"',
        ]);
    }
}
