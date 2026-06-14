<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| All Pest tests extend the Laravel TestCase automatically.
| RefreshDatabase is applied globally so every test starts with a clean DB.
|
*/

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');

function startCashierShift(User $user, float $openingFloat = 0): StaffShift
{
    return StaffShift::create([
        'tenant_id' => $user->tenant_id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'clock_in' => now(),
        'opening_float' => $openingFloat,
    ]);
}

function paymobHmac(array $payload, string $secret): string
{
    $obj = $payload['obj'];
    $fields = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
        'source_data.sub_type', 'source_data.type', 'success',
    ];

    $concatenated = '';
    foreach ($fields as $field) {
        $keys = explode('.', $field);
        $value = $obj;
        foreach ($keys as $key) {
            $value = $value[$key] ?? '';
        }
        $concatenated .= (string) $value;
    }

    return hash_hmac('sha512', $concatenated, $secret);
}
