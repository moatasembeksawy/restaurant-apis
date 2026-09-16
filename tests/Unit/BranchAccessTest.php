<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Support\Authorization\BranchAccess;

it('lets owners and managers access any branch', function (): void {
    $owner = new User(['role' => 'owner', 'branch_id' => 1]);
    $manager = new User(['role' => 'manager', 'branch_id' => 1]);

    expect(BranchAccess::canAccessAllBranches($owner))->toBeTrue();
    expect(BranchAccess::canAccess($owner, 99))->toBeTrue();
    expect(BranchAccess::canAccessAllBranches($manager))->toBeTrue();
    expect(BranchAccess::canAccess($manager, 99))->toBeTrue();
});

it('locks other staff to their assigned branch', function (): void {
    $cashier = new User(['role' => 'cashier', 'branch_id' => 4]);

    expect(BranchAccess::canAccessAllBranches($cashier))->toBeFalse();
    expect(BranchAccess::canAccess($cashier, 4))->toBeTrue();
    expect(BranchAccess::canAccess($cashier, 9))->toBeFalse();
    expect(BranchAccess::canAccess(null, 4))->toBeFalse();
});
