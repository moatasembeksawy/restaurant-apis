<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Models\StockCountLine;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockCountService
{
    public function __construct(private readonly StockService $stock) {}

    public function start(Branch $branch, User $user, ?string $notes = null): StockCount
    {
        $count = StockCount::create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'notes' => $notes,
        ]);

        AuditLogger::log('inventory.stock_count.started', $count, [
            'branch_id' => $branch->id,
        ]);

        return $count->load(['branch:id,name,name_ar', 'user:id,name']);
    }

    public function upsertLine(StockCount $count, Ingredient $ingredient, float $countedQuantity): StockCountLine
    {
        if ($count->status !== 'draft') {
            throw new InvalidArgumentException('Only draft stock counts can be edited.');
        }

        if ($ingredient->branch_id !== $count->branch_id) {
            throw new InvalidArgumentException('Ingredient does not belong to this branch.');
        }

        $systemQuantity = (float) $ingredient->current_stock;
        $variance = round($countedQuantity - $systemQuantity, 4);

        return StockCountLine::updateOrCreate(
            [
                'stock_count_id' => $count->id,
                'ingredient_id' => $ingredient->id,
            ],
            [
                'system_quantity' => $systemQuantity,
                'counted_quantity' => $countedQuantity,
                'variance' => $variance,
            ],
        )->load('ingredient:id,name_ar,unit,current_stock');
    }

    public function complete(StockCount $count, User $user): StockCount
    {
        if ($count->status !== 'draft') {
            throw new InvalidArgumentException('Stock count is not in draft status.');
        }

        if ($count->lines()->count() === 0) {
            throw new InvalidArgumentException('Add at least one counted line before completing.');
        }

        DB::transaction(function () use ($count, $user): void {
            $count->load('lines.ingredient');

            foreach ($count->lines as $line) {
                $ingredient = $line->ingredient;
                $current = (float) $ingredient->current_stock;
                $target = (float) $line->counted_quantity;
                $diff = round($target - $current, 4);

                if ($diff > 0) {
                    $this->stock->recordMovement(
                        ingredient: $ingredient,
                        type: 'adjustment',
                        quantity: $diff,
                        user: $user,
                        reference: $count,
                        branchId: $count->branch_id,
                        notes: "Stock count #{$count->id} reconciliation",
                        adjustmentDirection: 'in',
                    );
                } elseif ($diff < 0) {
                    $this->stock->recordMovement(
                        ingredient: $ingredient,
                        type: 'adjustment',
                        quantity: abs($diff),
                        user: $user,
                        reference: $count,
                        branchId: $count->branch_id,
                        notes: "Stock count #{$count->id} reconciliation",
                        adjustmentDirection: 'out',
                    );
                }
            }

            $count->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

        AuditLogger::log('inventory.stock_count.completed', $count->fresh(), [
            'lines' => $count->lines()->count(),
        ]);

        return $count->fresh(['branch:id,name,name_ar', 'user:id,name', 'lines.ingredient:id,name_ar,unit,current_stock']);
    }

    public function cancel(StockCount $count): StockCount
    {
        if ($count->status !== 'draft') {
            throw new InvalidArgumentException('Only draft stock counts can be cancelled.');
        }

        $count->update(['status' => 'cancelled']);

        AuditLogger::log('inventory.stock_count.cancelled', $count);

        return $count->fresh(['branch:id,name,name_ar', 'user:id,name', 'lines.ingredient:id,name_ar,unit,current_stock']);
    }

    /** @return array<string, mixed> */
    public function format(StockCount $count): array
    {
        $count->loadMissing(['branch:id,name,name_ar', 'user:id,name', 'lines.ingredient:id,name_ar,unit,current_stock']);

        return [
            'id' => $count->id,
            'branch_id' => $count->branch_id,
            'branch' => $count->branch ? [
                'id' => $count->branch->id,
                'name' => $count->branch->name,
                'name_ar' => $count->branch->name_ar,
            ] : null,
            'status' => $count->status,
            'notes' => $count->notes,
            'completed_at' => $count->completed_at?->toISOString(),
            'created_by' => $count->user ? [
                'id' => $count->user->id,
                'name' => $count->user->name,
            ] : null,
            'lines' => $count->lines->map(fn (StockCountLine $line) => [
                'id' => $line->id,
                'ingredient_id' => $line->ingredient_id,
                'name_ar' => $line->ingredient->name_ar,
                'unit' => $line->ingredient->unit,
                'system_quantity' => (float) $line->system_quantity,
                'counted_quantity' => (float) $line->counted_quantity,
                'variance' => (float) $line->variance,
                'current_stock' => (float) $line->ingredient->current_stock,
            ])->values()->all(),
            'created_at' => $count->created_at?->toISOString(),
        ];
    }
}
