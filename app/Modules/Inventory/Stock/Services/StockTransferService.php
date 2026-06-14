<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockTransferService
{
    public function __construct(private readonly StockService $stock) {}

    /** @return Collection<int, StockTransfer> */
    public function list(?int $branchId = null, int $limit = 50): Collection
    {
        return StockTransfer::query()
            ->with([
                'fromBranch:id,name',
                'toBranch:id,name',
                'fromIngredient:id,name_ar,unit',
                'toIngredient:id,name_ar,unit',
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
     * @return array{transfer: StockTransfer, from_ingredient: Ingredient, to_ingredient: Ingredient}
     */
    public function transfer(
        int $fromBranchId,
        int $toBranchId,
        int $ingredientId,
        float $quantity,
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

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $this->assertBranch($fromBranchId);
        $this->assertBranch($toBranchId);

        $source = Ingredient::query()
            ->where('branch_id', $fromBranchId)
            ->findOrFail($ingredientId);

        $target = Ingredient::query()
            ->where('branch_id', $toBranchId)
            ->where('name_ar', $source->name_ar)
            ->where('unit', $source->unit)
            ->first();

        if (! $target) {
            throw new InvalidArgumentException(
                "No matching ingredient at destination branch for {$source->name_ar} ({$source->unit}).",
            );
        }

        return DB::transaction(function () use ($source, $target, $fromBranchId, $toBranchId, $quantity, $user, $notes): array {
            $this->stock->recordMovement(
                ingredient: $source,
                type: 'adjustment',
                quantity: $quantity,
                user: $user,
                notes: $notes ?? "Transfer to branch #{$toBranchId}",
                branchId: $fromBranchId,
                adjustmentDirection: 'out',
            );

            try {
                $this->stock->recordMovement(
                    ingredient: $target,
                    type: 'adjustment',
                    quantity: $quantity,
                    user: $user,
                    notes: $notes ?? "Transfer from branch #{$fromBranchId}",
                    branchId: $toBranchId,
                    adjustmentDirection: 'in',
                );
            } catch (RuntimeException $e) {
                throw new InvalidArgumentException($e->getMessage());
            }

            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'from_ingredient_id' => $source->id,
                'to_ingredient_id' => $target->id,
                'user_id' => $user->id,
                'quantity' => $quantity,
                'notes' => $notes,
            ]);

            AuditLogger::log('inventory.transfer', $transfer, [
                'quantity' => $quantity,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
            ]);

            return [
                'transfer' => $transfer->load([
                    'fromBranch:id,name',
                    'toBranch:id,name',
                    'fromIngredient:id,name_ar,unit,current_stock',
                    'toIngredient:id,name_ar,unit,current_stock',
                ]),
                'from_ingredient' => $source->fresh(),
                'to_ingredient' => $target->fresh(),
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
