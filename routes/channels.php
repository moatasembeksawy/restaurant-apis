<?php

declare(strict_types=1);

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

    // Owner / manager can subscribe to any branch in their tenant
    if (in_array($user->role, ['owner', 'manager'])) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }

    // Other staff must belong to this branch
    if ((int) $user->branch_id === $branchId) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }

    return false;
});

/**
 * Private order channel — used for real-time order status updates to the waiter.
 */
Broadcast::channel('orders.{orderId}', function ($user, int $orderId): bool {
    $order = \App\Modules\POS\Orders\Models\Order::find($orderId);

    if (! $order) {
        return false;
    }

    // Must belong to same tenant
    if ($user->tenant_id !== $order->tenant_id) {
        return false;
    }

    // Waiter who placed the order or any manager/owner
    return $order->waiter_id === $user->id
        || in_array($user->role, ['owner', 'manager', 'cashier']);
});
