<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Models\User;
use App\Shared\Support\Authorization\BranchAccess;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    /** @var list<string> */
    private const BRANCH_KEYS = ['branch_id', 'from_branch_id', 'to_branch_id'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        foreach (self::BRANCH_KEYS as $key) {
            $value = $request->input($key) ?? $request->query($key);

            if ($value === null || $value === '') {
                continue;
            }

            BranchAccess::assertCanAccess($user, (int) $value);
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                BranchAccess::assertCanAccessModel($user, $parameter);
            }
        }

        return $next($request);
    }
}
