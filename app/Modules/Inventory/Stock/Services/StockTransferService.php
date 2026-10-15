<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\BranchAccess;
use App\Shared\Support\Authorization\BranchAccessDeniedException;
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
    public function list(?int $branchId = null, int $limit = 50, ?string $status = null): Collection
    {
        return StockTransfer::query()
            ->with($this->relations())
            ->when($branchId, fn ($q, $id) => $q->where(function ($query) use ($id): void {
                $query->where('from_branch_id', $id)->orWhere('to_branch_id', $id);
            }))
            ->when($status, fn ($q, $value) => $q->where('status', $value))
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function present(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load($this->relations());
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>  $items
     */
    public function requestTransfer(
        int $fromBranchId,
        int $toBranchId,
        array $items,
        User $user,
        ?string $notes = null,
    ): StockTransfer {
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
        BranchAccess::assertCanAccess($user, $toBranchId);
        $this->assertUniqueIngredients($items);

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $items, $user, $notes): StockTransfer {
            foreach ($items as $line) {
                $quantity = round((float) $line['quantity'], 4);

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $source = $this->sourceStock($fromBranchId, (int) $line['ingredient_id']);

                if (! $source) {
                    throw new InvalidArgumentException('Ingredient is not stocked at the source branch.');
                }
            }

            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'user_id' => $user->id,
                'status' => StockTransfer::STATUS_PENDING,
                'notes' => $notes,
            ]);

            foreach ($items as $line) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'ingredient_id' => (int) $line['ingredient_id'],
                    'quantity' => round((float) $line['quantity'], 4),
                ]);
            }

            AuditLogger::log('inventory.transfer.requested', $transfer, [
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'lines' => count($items),
            ]);

            return $this->present($transfer);
        });
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>|null  $lines
     */
    public function send(StockTransfer $transfer, User $user, ?array $lines = null): StockTransfer
    {
        BranchAccess::assertCanAccess($user, (int) $transfer->from_branch_id);

        return DB::transaction(function () use ($transfer, $user, $lines): StockTransfer {
            $transfer = $this->lock($transfer);

            if ($transfer->status !== StockTransfer::STATUS_PENDING || $this->hasMovement($transfer, 'transfer_out')) {
                throw new InvalidArgumentException('Only a pending request can be sent.');
            }

            $quantities = $this->resolveQuantities($transfer, $lines, 'quantity', 'Sent quantity cannot exceed the requested quantity.');

            if (round(array_sum($quantities), 4) <= 0) {
                throw new InvalidArgumentException('Send at least one ingredient, or cancel the request.');
            }

            foreach ($transfer->items->sortBy('ingredient_id') as $item) {
                $sentQty = $quantities[(int) $item->ingredient_id];
                $source = $this->sourceStock((int) $transfer->from_branch_id, (int) $item->ingredient_id);

                if (! $source) {
                    throw new InvalidArgumentException('Ingredient is not stocked at the source branch.');
                }

                if ($sentQty > 0) {
                    $this->move(
                        stock: $source,
                        type: 'transfer_out',
                        quantity: $sentQty,
                        user: $user,
                        notes: $transfer->notes ?? "Transfer to branch #{$transfer->to_branch_id}",
                        transfer: $transfer,
                        branchId: (int) $transfer->from_branch_id,
                    );
                }

                $item->update([
                    'sent_quantity' => $sentQty,
                    'unit_cost' => $source->unit_cost,
                ]);
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'sent_by_id' => $user->id,
                'sent_at' => now(),
            ]);

            AuditLogger::log('inventory.transfer.sent', $transfer, [
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
            ]);

            return $this->present($transfer);
        });
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>|null  $lines
     */
    public function receive(StockTransfer $transfer, User $user, ?array $lines = null): StockTransfer
    {
        BranchAccess::assertCanAccess($user, (int) $transfer->to_branch_id);

        return DB::transaction(function () use ($transfer, $user, $lines): StockTransfer {
            $transfer = $this->lock($transfer);

            if ($transfer->status !== StockTransfer::STATUS_IN_TRANSIT || $this->hasMovement($transfer, 'transfer_in')) {
                throw new InvalidArgumentException('Only a sent transfer can be received.');
            }

            $quantities = $this->resolveQuantities($transfer, $lines, 'sent_quantity', 'Received quantity cannot exceed the sent quantity.');

            foreach ($transfer->items->sortBy('ingredient_id') as $item) {
                $receivedQty = $quantities[(int) $item->ingredient_id];
                $unitCost = $item->unit_cost !== null ? (float) $item->unit_cost : null;

                if ($receivedQty > 0) {
                    $target = $this->destinationStock($transfer, $item, $unitCost);

                    $this->move(
                        stock: $target,
                        type: 'transfer_in',
                        quantity: $receivedQty,
                        user: $user,
                        unitCost: $unitCost,
                        notes: $transfer->notes ?? "Transfer from branch #{$transfer->from_branch_id}",
                        transfer: $transfer,
                        branchId: (int) $transfer->to_branch_id,
                    );
                }

                $item->update(['received_quantity' => $receivedQty]);
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_COMPLETED,
                'received_by_id' => $user->id,
                'received_at' => now(),
            ]);

            AuditLogger::log('inventory.transfer.received', $transfer, [
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
            ]);

            return $this->present($transfer);
        });
    }

    public function cancel(StockTransfer $transfer, User $user): StockTransfer
    {
        $fromBranchId = (int) $transfer->from_branch_id;
        $toBranchId = (int) $transfer->to_branch_id;

        if (! BranchAccess::canAccess($user, $fromBranchId) && ! BranchAccess::canAccess($user, $toBranchId)) {
            throw new BranchAccessDeniedException;
        }

        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $transfer = $this->lock($transfer);

            if ($transfer->status !== StockTransfer::STATUS_PENDING) {
                throw new InvalidArgumentException('Only a pending request can be cancelled.');
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_by_id' => $user->id,
                'cancelled_at' => now(),
            ]);

            AuditLogger::log('inventory.transfer.cancelled', $transfer, [
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
            ]);

            return $this->present($transfer);
        });
    }

    private function lock(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
        $locked->load(['items' => fn ($query) => $query->orderBy('ingredient_id')]);

        return $locked;
    }

    private function hasMovement(StockTransfer $transfer, string $type): bool
    {
        return StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->where('type', $type)
            ->exists();
    }

    private function move(
        InventoryStock $stock,
        string $type,
        float $quantity,
        User $user,
        StockTransfer $transfer,
        int $branchId,
        ?float $unitCost = null,
        ?string $notes = null,
    ): void {
        try {
            $this->stock->recordMovement(
                stock: $stock,
                type: $type,
                quantity: $quantity,
                user: $user,
                unitCost: $unitCost,
                notes: $notes,
                reference: $transfer,
                branchId: $branchId,
            );
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
    }

    private function destinationStock(StockTransfer $transfer, StockTransferItem $item, ?float $unitCost): InventoryStock
    {
        $target = $this->ingredients->stockAtBranch((int) $item->ingredient_id, (int) $transfer->to_branch_id);

        if ($target) {
            return $target;
        }

        return $this->ingredients->openAtBranch([
            'ingredient_id' => $item->ingredient_id,
            'branch_id' => $transfer->to_branch_id,
            'current_stock' => 0,
            'unit_cost' => $unitCost ?? 0,
            'is_active' => true,
        ]);
    }

    private function sourceStock(int $branchId, int $ingredientId): ?InventoryStock
    {
        return InventoryStock::query()
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->first();
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>|null  $overrides
     * @return array<int, float>
     */
    private function resolveQuantities(StockTransfer $transfer, ?array $overrides, string $basis, string $exceededMessage): array
    {
        /** @var array<int, float> $caps */
        $caps = [];

        foreach ($transfer->items as $item) {
            $cap = $basis === 'sent_quantity' ? $item->sent_quantity : $item->quantity;
            $caps[(int) $item->ingredient_id] = round((float) $cap, 4);
        }

        $quantities = $caps;

        if ($overrides === null) {
            return $quantities;
        }

        foreach ($overrides as $line) {
            $ingredientId = (int) $line['ingredient_id'];

            if (! array_key_exists($ingredientId, $caps)) {
                throw new InvalidArgumentException('Ingredient is not on this transfer.');
            }

            $quantity = round((float) $line['quantity'], 4);

            if ($this->greaterThan($quantity, $caps[$ingredientId])) {
                throw new InvalidArgumentException($exceededMessage);
            }

            $quantities[$ingredientId] = $quantity;
        }

        return $quantities;
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>  $items
     */
    private function assertUniqueIngredients(array $items): void
    {
        $ids = array_map(fn (array $line): int => (int) $line['ingredient_id'], $items);

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Duplicate ingredient on this transfer.');
        }
    }

    private function greaterThan(float $left, float $right): bool
    {
        return round($left, 4) > round($right, 4);
    }

    private function assertBranch(int $branchId): void
    {
        if (! Branch::query()->where('id', $branchId)->exists()) {
            throw new InvalidArgumentException('Invalid branch for this tenant.');
        }
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'fromBranch:id,name',
            'toBranch:id,name',
            'items.ingredient',
            'user:id,name',
            'sentBy:id,name',
            'receivedBy:id,name',
            'cancelledBy:id,name',
        ];
    }
}
