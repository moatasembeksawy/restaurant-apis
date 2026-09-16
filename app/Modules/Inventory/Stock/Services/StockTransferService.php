<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\BranchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockTransferService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly IngredientService $ingredients,
    ) {}

    /** @return Collection<int, StockTransfer> */
    public function list(?int $branchId = null, int $limit = 50): Collection
    {
        return StockTransfer::query()
            ->with([
                'fromBranch:id,name',
                'toBranch:id,name',
                'items.ingredient',
                'user:id,name',
            ])
            ->when($branchId, fn ($q, $id) => $q->where(function ($query) use ($id): void {
                $query->where('from_branch_id', $id)->orWhere('to_branch_id', $id);
            }))
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float}>  $items
     * @return array{transfer: StockTransfer, from_ingredient: InventoryStock, to_ingredient: InventoryStock, created_at_destination: bool}
     */
    public function transfer(
        int $fromBranchId,
        int $toBranchId,
        array $items,
        User $user,
        ?string $notes = null,
    ): array {
        /** @var Tenant $tenant */
        $tenant = app('tenant');

        if (! $tenant->hasFeature('multi_branch')) {
            throw new InvalidArgumentException('Cross-branch transfers require Enterprise plan.');
        }

        if ($fromBranchId === $toBranchId) {
            throw new InvalidArgumentException('Source and destination branches must differ.');
        }

        if ($items === []) {
            throw new InvalidArgumentException('Add at least one transfer line.');
        }

        $this->assertBranch($fromBranchId);
        $this->assertBranch($toBranchId);
        BranchAccess::assertCanAccess($user, $fromBranchId);
        BranchAccess::assertCanAccess($user, $toBranchId);

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $items, $user, $notes): array {
            $createdAtDestination = false;
            $firstSource = null;
            $firstTarget = null;

            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'user_id' => $user->id,
                'status' => 'completed',
                'notes' => $notes,
            ]);

            foreach ($items as $line) {
                $quantity = (float) $line['quantity'];

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $source = InventoryStock::query()
                    ->where('branch_id', $fromBranchId)
                    ->where('ingredient_id', $line['ingredient_id'])
                    ->first();

                if (! $source) {
                    throw new InvalidArgumentException('Ingredient is not stocked at the source branch.');
                }

                $target = InventoryStock::query()
                    ->where('branch_id', $toBranchId)
                    ->where('ingredient_id', $source->ingredient_id)
                    ->first();

                if (! $target) {
                    $target = $this->ingredients->openAtBranch([
                        'ingredient_id' => $source->ingredient_id,
                        'branch_id' => $toBranchId,
                        'current_stock' => 0,
                        'reorder_level' => $source->reorder_level,
                        'unit_cost' => $source->unit_cost,
                        'is_active' => true,
                    ]);
                    $createdAtDestination = true;
                }

                $this->stock->recordMovement(
                    stock: $source,
                    type: 'transfer_out',
                    quantity: $quantity,
                    user: $user,
                    notes: $notes ?? "Transfer to branch #{$toBranchId}",
                    reference: $transfer,
                    branchId: $fromBranchId,
                );

                try {
                    $this->stock->recordMovement(
                        stock: $target,
                        type: 'transfer_in',
                        quantity: $quantity,
                        user: $user,
                        notes: $notes ?? "Transfer from branch #{$fromBranchId}",
                        reference: $transfer,
                        branchId: $toBranchId,
                    );
                } catch (RuntimeException $e) {
                    throw new InvalidArgumentException($e->getMessage());
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'ingredient_id' => $source->ingredient_id,
                    'quantity' => $quantity,
                ]);

                $firstSource ??= $source;
                $firstTarget ??= $target;
            }

            AuditLogger::log('inventory.transfer', $transfer, [
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'lines' => count($items),
            ]);

            /** @var InventoryStock $firstSource */
            /** @var InventoryStock $firstTarget */
            return [
                'transfer' => $transfer->load([
                    'fromBranch:id,name',
                    'toBranch:id,name',
                    'items.ingredient',
                ]),
                'from_ingredient' => $firstSource->fresh('ingredient'),
                'to_ingredient' => $firstTarget->fresh('ingredient'),
                'created_at_destination' => $createdAtDestination,
            ];
        });
    }

    private function assertBranch(int $branchId): void
    {
        if (! Branch::query()->where('id', $branchId)->exists()) {
            throw new InvalidArgumentException('Invalid branch for this tenant.');
        }
    }
}
