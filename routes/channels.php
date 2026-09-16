<?php

declare(strict_types=1);

use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Authorization\BranchAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channels are authenticated via Sanctum Bearer token.
| The frontend must POST /api/broadcasting/auth with the token.
|
*/

/**
 * Private branch channel — used by kitchen display and table status boards.
 * Accessible by:
 *   - Any staff member of the same branch
 *   - Owners and managers of the same tenant (any branch)
 *   - Kitchen display tokens (kitchen:read ability)
 */
Broadcast::channel('branch.{branchId}', function ($user, int $branchId): bool|array {
    $tenant = app()->bound('tenant') ? app('tenant') : null;

    // Verify the tenant matches
    if ($tenant && $user->tenant_id !== $tenant->id) {
        return false;
    }

    if (! BranchAccess::canAccess($user, $branchId)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ];
});

/**
 * Private order channel — used for real-time order status updates to the waiter.
 */
Broadcast::channel('orders.{orderId}', function ($user, int $orderId): bool {
    $order = Order::find($orderId);

    if (! $order) {
        return false;
    }

    // Must belong to same tenant
    if ($user->tenant_id !== $order->tenant_id) {
        return false;
    }

    if (! BranchAccess::canAccess($user, (int) $order->branch_id)) {
        return false;
    }

    // Waiter who placed the order or any manager/owner/cashier of this branch
    return $order->waiter_id === $user->id
        || in_array($user->role, ['owner', 'manager', 'cashier']);
});
