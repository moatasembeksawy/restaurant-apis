<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes a tenant's financial activity and leaves setup data in place.
 *
 * Removed: orders and line items, payments, invoices, refunds, offer redemptions,
 * loyalty ledger entries, shifts, cash movements, expenses, and purchase orders.
 *
 * Kept: menu, packages, offers, tables, staff, customers, branches, expense
 * categories, suppliers, and inventory setup. Occupied tables are freed and
 * customer spend totals are zeroed because those figures come from the deleted sales.
 */
class TenantFinancialPurgeService
{
    /**
     * @return array{deleted: array<string, int>, reset: array<string, int>}
     */
    public function purge(Tenant $tenant, PlatformAdmin $admin, string $confirmation): array
    {
        if ($confirmation !== $tenant->subdomain) {
            throw new InvalidArgumentException('Confirmation must match the tenant subdomain.');
        }

        $result = DB::transaction(function () use ($tenant): array {
            $tenantId = $tenant->id;

            $deleted = [
                'invoices' => $this->deleteForTenant('invoices', $tenantId),
                'payment_splits' => $this->deleteChildren('payment_splits', 'payment_id', 'payments', $tenantId),
                'payment_refunds' => $this->deleteForTenant('payment_refunds', $tenantId),
                'payments' => $this->deleteForTenant('payments', $tenantId),
                'offer_redemptions' => $this->deleteChildren('offer_redemptions', 'order_id', 'orders', $tenantId),
                'order_adjustments' => $this->deleteChildren('order_adjustments', 'order_id', 'orders', $tenantId),
                'order_items' => $this->deleteOrderItems($tenantId),
                'loyalty_transactions' => $this->deleteForTenant('loyalty_transactions', $tenantId),
                'orders' => $this->deleteForTenant('orders', $tenantId),
                'expenses' => $this->deleteForTenant('expenses', $tenantId),
                'cash_movements' => $this->deleteCashMovements($tenantId),
                'staff_shifts' => $this->deleteForTenant('staff_shifts', $tenantId),
                'purchase_order_items' => $this->deleteChildren('purchase_order_items', 'purchase_order_id', 'purchase_orders', $tenantId),
                'purchase_orders' => $this->deleteForTenant('purchase_orders', $tenantId),
            ];

            $deleted['stock_movement_links'] = DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->whereIn('reference_type', [Order::class, PurchaseOrder::class])
                ->update([
                    'reference_type' => null,
                    'reference_id' => null,
                    'updated_at' => now(),
                ]);

            $reset = [
                'customers' => DB::table('customers')
                    ->where('tenant_id', $tenantId)
                    ->update([
                        'loyalty_points' => 0,
                        'visit_count' => 0,
                        'total_spent' => 0,
                        'last_order_at' => null,
                        'updated_at' => now(),
                    ]),
                'tables_freed' => DB::table('floor_tables')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'occupied')
                    ->update([
                        'status' => 'free',
                        'updated_at' => now(),
                    ]),
            ];

            return [
                'deleted' => $deleted,
                'reset' => $reset,
            ];
        });

        activity('platform')
            ->causedBy($admin)
            ->performedOn($tenant)
            ->withProperties([
                'tenant_id' => $tenant->id,
                'deleted' => $result['deleted'],
                'reset' => $result['reset'],
            ])
            ->log('purged tenant financial data');

        return $result;
    }

    private function deleteForTenant(string $table, int $tenantId): int
    {
        return DB::table($table)->where('tenant_id', $tenantId)->delete();
    }

    private function deleteChildren(string $table, string $foreignKey, string $parentTable, int $tenantId): int
    {
        return DB::table($table)
            ->whereIn($foreignKey, function ($query) use ($parentTable, $tenantId): void {
                $query->select('id')->from($parentTable)->where('tenant_id', $tenantId);
            })
            ->delete();
    }

    private function deleteOrderItems(int $tenantId): int
    {
        DB::table('order_items')
            ->whereIn('order_id', function ($query) use ($tenantId): void {
                $query->select('id')->from('orders')->where('tenant_id', $tenantId);
            })
            ->update(['parent_id' => null]);

        return $this->deleteChildren('order_items', 'order_id', 'orders', $tenantId);
    }

    private function deleteCashMovements(int $tenantId): int
    {
        DB::table('cash_movements')
            ->where('tenant_id', $tenantId)
            ->update(['reversal_of_id' => null]);

        return $this->deleteForTenant('cash_movements', $tenantId);
    }
}
