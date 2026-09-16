<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BranchAccess
{
    /** @var list<string> */
    public const CROSS_BRANCH_ROLES = ['owner', 'manager'];

    public static function canAccessAllBranches(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::CROSS_BRANCH_ROLES, true);
    }

    public static function canAccess(?User $user, int $branchId): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::canAccessAllBranches($user)) {
            return true;
        }

        return $user->branch_id !== null && (int) $user->branch_id === $branchId;
    }

    public static function assertCanAccess(User $user, int $branchId): void
    {
        if (! Branch::query()->whereKey($branchId)->exists() || ! self::canAccess($user, $branchId)) {
            throw new BranchAccessDeniedException;
        }
    }

    public static function assertCanAccessModel(User $user, Model $model): void
    {
        if ($model instanceof OrderItem) {
            $order = $model->order;

            if ($order === null || $order->branch_id === null) {
                throw new BranchAccessDeniedException;
            }

            self::assertCanAccess($user, (int) $order->branch_id);

            return;
        }

        if ($model instanceof StockTransfer) {
            self::assertCanAccess($user, (int) $model->from_branch_id);
            self::assertCanAccess($user, (int) $model->to_branch_id);

            return;
        }

        $attributes = $model->getAttributes();

        if (! array_key_exists('branch_id', $attributes) && ! in_array('branch_id', $model->getFillable(), true)) {
            return;
        }

        $branchId = $attributes['branch_id'] ?? null;

        if ($branchId === null) {
            return;
        }

        self::assertCanAccess($user, (int) $branchId);
    }

    /**
     * @return int|null Null means the user may query every branch in the tenant.
     */
    public static function filterBranchId(User $user, int|string|null $requested = null): ?int
    {
        if ($requested !== null && $requested !== '') {
            $branchId = (int) $requested;
            self::assertCanAccess($user, $branchId);

            return $branchId;
        }

        if (self::canAccessAllBranches($user)) {
            return null;
        }

        return $user->branch_id !== null ? (int) $user->branch_id : 0;
    }

    public static function constrain(
        Builder $query,
        User $user,
        int|string|null $requestedBranchId = null,
        string $column = 'branch_id',
    ): Builder {
        $branchId = self::filterBranchId($user, $requestedBranchId);

        if ($branchId === null) {
            return $query;
        }

        return $query->where($column, $branchId);
    }

    public static function constrainNullable(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        if (self::canAccessAllBranches($user)) {
            return $query;
        }

        $branchId = $user->branch_id;

        if ($branchId === null) {
            return $query->whereNull($column);
        }

        return $query->where(function (Builder $inner) use ($column, $branchId): void {
            $inner->where($column, $branchId)->orWhereNull($column);
        });
    }
}
