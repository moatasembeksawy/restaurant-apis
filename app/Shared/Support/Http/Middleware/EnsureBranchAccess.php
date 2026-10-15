<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use App\Models\User;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Authorization\BranchAccessDeniedException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $fromBranchId = $request->input('from_branch_id') ?? $request->query('from_branch_id');
        $toBranchId = $request->input('to_branch_id') ?? $request->query('to_branch_id');
        $hasFrom = $fromBranchId !== null && $fromBranchId !== '';
        $hasTo = $toBranchId !== null && $toBranchId !== '';

        if ($hasFrom && $hasTo) {
            if (! BranchAccess::canAccess($user, (int) $fromBranchId) && ! BranchAccess::canAccess($user, (int) $toBranchId)) {
                throw new BranchAccessDeniedException;
            }
        } else {
            if ($hasFrom) {
                BranchAccess::assertCanAccess($user, (int) $fromBranchId);
            }

            if ($hasTo) {
                BranchAccess::assertCanAccess($user, (int) $toBranchId);
            }
        }

        $branchId = $request->input('branch_id') ?? $request->query('branch_id');

        if ($branchId !== null && $branchId !== '') {
            BranchAccess::assertCanAccess($user, (int) $branchId);
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                BranchAccess::assertCanAccessModel($user, $parameter);
            }
        }

        return $next($request);
    }
}
