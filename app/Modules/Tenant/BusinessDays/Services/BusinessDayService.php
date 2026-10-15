<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Services;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Services\IngredientService;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\Inventory\Stock\Services\StockTransferService;
use App\Modules\Tenant\BusinessDays\Models\BusinessDay;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\BranchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BusinessDayService
{
    public function __construct(
        private readonly BusinessDayReportService $reports,
        private readonly IngredientService $ingredients,
        private readonly StockService $stock,
        private readonly StockTransferService $transfers,
    ) {}

    /** @return Collection<int, BusinessDay> */
    public function list(?int $branchId = null, ?string $status = null, ?string $date = null): Collection
    {
        return BusinessDay::query()
            ->with(['branch:id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->when($branchId, fn ($query, $id) => $query->where('branch_id', $id))
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->when($date, fn ($query, $value) => $query->whereDate('business_date', $value))
            ->latest('opened_at')
            ->limit(100)
            ->get();
    }

    public function current(int $branchId): ?BusinessDay
    {
        return BusinessDay::query()
            ->with(['branch:id,name', 'openedBy:id,name'])
            ->where('branch_id', $branchId)
            ->where('status', BusinessDay::STATUS_OPEN)
            ->first();
    }

    public function open(int $branchId, ?User $user = null, ?string $notes = null): BusinessDay
    {
        if ($user) {
            BranchAccess::assertCanAccess($user, $branchId);
        }

        return DB::transaction(function () use ($branchId, $user, $notes): BusinessDay {
            Branch::query()->lockForUpdate()->findOrFail($branchId);

            $existing = BusinessDay::query()
                ->where('branch_id', $branchId)
                ->where('status', BusinessDay::STATUS_OPEN)
                ->first();

            if ($existing) {
                throw new InvalidArgumentException('This branch already has an open business day.');
            }

            $day = BusinessDay::create([
                'branch_id' => $branchId,
                'opened_by_id' => $user?->id,
                'business_date' => now()->toDateString(),
                'status' => BusinessDay::STATUS_OPEN,
                'active_key' => $this->activeKey($branchId),
                'next_order_number' => 1,
                'opened_at' => now(),
                'opening_notes' => $notes,
                'opening_stock' => $this->stockSnapshot($branchId),
            ]);

            AuditLogger::log('business_day.opened', $day, [
                'branch_id' => $branchId,
                'business_date' => $day->business_date->toDateString(),
            ]);

            return $day->load(['branch:id,name', 'openedBy:id,name']);
        });
    }

    public function resolveOrOpen(int $branchId, ?User $user = null): BusinessDay
    {
        $current = $this->current($branchId);

        if ($current) {
            return $current;
        }

        return $this->open($branchId, $user, 'Opened automatically by the first operation.');
    }

    public function close(BusinessDay $day, User $user, ?string $notes = null): BusinessDay
    {
        BranchAccess::assertCanAccess($user, (int) $day->branch_id);

        return DB::transaction(function () use ($day, $user, $notes): BusinessDay {
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);

            if (! $day->isOpen()) {
                throw new InvalidArgumentException('This business day is already closed.');
            }

            if ($day->shifts()->whereNull('clock_out')->exists()) {
                throw new InvalidArgumentException('Clock out all active shifts before closing the business day.');
            }

            if ($day->orders()->whereIn('status', ['pending', 'active', 'cooking', 'ready', 'completed'])->exists()) {
                throw new InvalidArgumentException('Complete, pay, or cancel all open orders before closing the business day.');
            }

            $summary = $this->reports->build($day);
            $closedAt = now();
            $summary['status'] = BusinessDay::STATUS_CLOSED;
            $summary['closed_at'] = $closedAt->toIso8601String();

            $day->update([
                'status' => BusinessDay::STATUS_CLOSED,
                'active_key' => null,
                'closed_by_id' => $user->id,
                'closed_at' => $closedAt,
                'closing_notes' => $notes,
                'closing_summary' => $summary,
            ]);

            AuditLogger::log('business_day.closed', $day, [
                'branch_id' => $day->branch_id,
                'orders_count' => $summary['orders']['total'],
                'net_sales' => $summary['orders']['net_sales'],
            ]);

            return $day->fresh(['branch:id,name', 'openedBy:id,name', 'closedBy:id,name']) ?? $day;
        });
    }

    public function allocateOrderNumber(BusinessDay $day): int
    {
        $locked = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);

        if (! $locked->isOpen()) {
            throw new InvalidArgumentException('Orders cannot be added to a closed business day.');
        }

        $number = (int) $locked->next_order_number;
        $locked->increment('next_order_number');

        return $number;
    }

    /** @return array<string, mixed> */
    public function report(BusinessDay $day): array
    {
        if (! $day->isOpen() && is_array($day->closing_summary)) {
            return $day->closing_summary;
        }

        return $this->reports->build($day);
    }

    /** @return list<array<string, mixed>> */
    public function remainingStock(BusinessDay $day): array
    {
        if (! $day->isOpen() && is_array($day->closing_summary)) {
            return $day->closing_summary['inventory']['remaining'] ?? [];
        }

        return $this->stockSnapshot((int) $day->branch_id);
    }

    public function recordWaste(
        BusinessDay $day,
        User $user,
        int $ingredientId,
        float $quantity,
        ?string $reason = null,
    ): StockMovement {
        BranchAccess::assertCanAccess($user, (int) $day->branch_id);

        if (! $day->isOpen()) {
            throw new InvalidArgumentException('Waste cannot be added to a closed business day.');
        }

        $stock = $this->ingredients->stockAtBranch($ingredientId, (int) $day->branch_id);

        if (! $stock) {
            throw new InvalidArgumentException('Ingredient is not stocked at this branch.');
        }

        try {
            return $this->stock->recordMovement(
                stock: $stock,
                type: 'waste',
                quantity: $quantity,
                user: $user,
                notes: $reason,
                branchId: (int) $day->branch_id,
                businessDayId: $day->id,
            );
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float|int|string}>|null  $items
     */
    public function returnStock(
        BusinessDay $day,
        User $user,
        ?int $toBranchId = null,
        ?array $items = null,
        bool $returnRemaining = false,
        ?string $notes = null,
    ): StockTransfer {
        BranchAccess::assertCanAccess($user, (int) $day->branch_id);

        if (! $day->isOpen()) {
            throw new InvalidArgumentException('Stock cannot be returned from a closed business day.');
        }

        $destinationId = $toBranchId ?? $this->defaultWarehouseBranchId((int) $day->branch_id);

        if ($destinationId === null) {
            throw new InvalidArgumentException('to_branch_id is required when returning stock to main inventory.');
        }

        if ($destinationId === (int) $day->branch_id) {
            throw new InvalidArgumentException('Main inventory must be a different branch.');
        }

        $lines = $items ?? [];

        if ($returnRemaining) {
            $lines = collect($this->remainingStock($day))
                ->filter(fn (array $row): bool => (float) $row['quantity'] > 0)
                ->map(fn (array $row): array => [
                    'ingredient_id' => (int) $row['ingredient_id'],
                    'quantity' => (float) $row['quantity'],
                ])
                ->values()
                ->all();
        }

        if ($lines === []) {
            throw new InvalidArgumentException(
                $returnRemaining
                    ? 'There is no remaining stock to return.'
                    : 'Add at least one ingredient, or set return_remaining.',
            );
        }

        $transfer = $this->transfers->requestTransfer(
            fromBranchId: (int) $day->branch_id,
            toBranchId: $destinationId,
            items: $lines,
            user: $user,
            notes: $notes ?? "Return from business day #{$day->id}",
        );

        return $this->transfers->send($transfer, $user);
    }

    /** @return list<array<string, mixed>> */
    public function stockSnapshot(int $branchId): array
    {
        return InventoryStock::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('ingredient:id,name_ar,name_en,unit')
            ->get()
            ->map(fn (InventoryStock $stock): array => [
                'ingredient_id' => $stock->ingredient_id,
                'name_ar' => $stock->ingredient?->name_ar,
                'name_en' => $stock->ingredient?->name_en,
                'unit' => $stock->ingredient?->unit,
                'quantity' => (float) $stock->current_stock,
                'unit_cost' => (float) $stock->unit_cost,
                'value' => round((float) $stock->current_stock * (float) $stock->unit_cost, 2),
            ])
            ->values()
            ->all();
    }

    private function defaultWarehouseBranchId(int $fromBranchId): ?int
    {
        $warehouseId = Branch::query()
            ->where('is_default', true)
            ->whereKeyNot($fromBranchId)
            ->value('id');

        return $warehouseId !== null ? (int) $warehouseId : null;
    }

    private function activeKey(int $branchId): string
    {
        return app('tenant')->id.':'.$branchId;
    }
}
