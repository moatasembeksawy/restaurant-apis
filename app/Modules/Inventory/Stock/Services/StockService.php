<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\PurchaseOrder;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockService
{
    /** @var array<string, int> */
    private const DIRECTION = [
        'purchase' => 1,
        'waste' => -1,
        'sale' => -1,
        'adjustment' => 1,
    ];

    public function recordMovement(
        Ingredient $ingredient,
        string $type,
        float $quantity,
        ?User $user = null,
        ?float $unitCost = null,
        ?string $notes = null,
        ?Model $reference = null,
        ?int $branchId = null,
        ?string $adjustmentDirection = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        if (! isset(self::DIRECTION[$type])) {
            throw new InvalidArgumentException("Invalid movement type: {$type}");
        }

        return DB::transaction(function () use ($ingredient, $type, $quantity, $user, $unitCost, $notes, $reference, $branchId, $adjustmentDirection): StockMovement {
            $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);

            $signedQty = match ($type) {
                'adjustment' => $adjustmentDirection === 'out' ? -$quantity : $quantity,
                default => $quantity * self::DIRECTION[$type],
            };

            $newStock = (float) $ingredient->current_stock + $signedQty;

            if ($newStock < 0) {
                throw new RuntimeException("Insufficient stock for {$ingredient->name_ar}.");
            }

            $movement = StockMovement::create([
                'ingredient_id' => $ingredient->id,
                'branch_id' => $branchId ?? $ingredient->branch_id,
                'user_id' => $user?->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $ingredient->unit_cost,
                'notes' => $notes,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
            ]);

            $ingredient->update(['current_stock' => $newStock]);

            if ($type === 'purchase' && $unitCost !== null) {
                $this->updateWeightedAverageCost($ingredient, $quantity, $unitCost);
            }

            AuditLogger::log("inventory.{$type}", $movement, [
                'ingredient_id' => $ingredient->id,
                'quantity' => $quantity,
                'new_stock' => $newStock,
            ]);

            return $movement;
        });
    }

    public function deductForOrder(Order $order): void
    {
        if (StockMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'sale')
            ->exists()) {
            return;
        }

        $order->load('items');

        DB::transaction(function () use ($order): void {
            foreach ($order->items as $orderItem) {
                $recipes = Recipe::query()
                    ->where('menu_item_id', $orderItem->menu_item_id)
                    ->with('ingredient')
                    ->get();

                foreach ($recipes as $recipe) {
                    $deductQty = (float) $recipe->quantity * (int) $orderItem->quantity;

                    if ($deductQty <= 0) {
                        continue;
                    }

                    $this->recordMovement(
                        ingredient: $recipe->ingredient,
                        type: 'sale',
                        quantity: $deductQty,
                        reference: $order,
                        branchId: $order->branch_id,
                        notes: "Order #{$order->id} — {$orderItem->item_name_ar}",
                    );
                }
            }
        });
    }

    public function receivePurchaseOrder(PurchaseOrder $purchaseOrder, ?User $user = null): PurchaseOrder
    {
        if ($purchaseOrder->status === 'received') {
            throw new InvalidArgumentException('Purchase order already received.');
        }

        if (! in_array($purchaseOrder->status, ['draft', 'ordered'])) {
            throw new InvalidArgumentException('Purchase order cannot be received.');
        }

        DB::transaction(function () use ($purchaseOrder, $user): void {
            $purchaseOrder->load('items.ingredient');

            foreach ($purchaseOrder->items as $line) {
                $this->recordMovement(
                    ingredient: $line->ingredient,
                    type: 'purchase',
                    quantity: (float) $line->quantity,
                    user: $user,
                    unitCost: (float) $line->unit_cost,
                    reference: $purchaseOrder,
                    branchId: $purchaseOrder->branch_id,
                    notes: "PO #{$purchaseOrder->id}",
                );
            }

            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);
        });

        return $purchaseOrder->fresh(['items', 'supplier']);
    }

    /**
     * @return Collection<int, Ingredient>
     */
    public function lowStockIngredients(?int $branchId = null): Collection
    {
        return Ingredient::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->orderBy('name_ar')
            ->get();
    }

    /**
     * @return array{lines: array<int, array<string, mixed>>, total_cost: float, menu_price: float, profit_margin: float|null}
     */
    public function recipeCost(MenuItem $menuItem): array
    {
        $recipes = Recipe::query()
            ->where('menu_item_id', $menuItem->id)
            ->with('ingredient')
            ->get();

        $lines = $recipes->map(fn (Recipe $recipe) => [
            'ingredient_id' => $recipe->ingredient_id,
            'name_ar' => $recipe->ingredient->name_ar,
            'quantity' => $recipe->quantity,
            'unit' => $recipe->ingredient->unit,
            'unit_cost' => $recipe->ingredient->unit_cost,
            'line_cost' => $recipe->lineCost(),
        ])->values()->all();

        $totalCost = round($recipes->sum(fn (Recipe $r) => $r->lineCost()), 4);
        $menuPrice = (float) $menuItem->price;

        return [
            'menu_item_id' => $menuItem->id,
            'name_ar' => $menuItem->name_ar,
            'lines' => $lines,
            'total_cost' => $totalCost,
            'menu_price' => $menuPrice,
            'profit_margin' => $menuPrice > 0
                ? round((($menuPrice - $totalCost) / $menuPrice) * 100, 2)
                : null,
        ];
    }

    /**
     * @param  array<int, array{ingredient_id: int, quantity: float}>  $lines
     */
    public function syncRecipe(MenuItem $menuItem, array $lines): Collection
    {
        Recipe::query()->where('menu_item_id', $menuItem->id)->delete();

        $created = collect();

        foreach ($lines as $line) {
            $created->push(Recipe::create([
                'menu_item_id' => $menuItem->id,
                'ingredient_id' => $line['ingredient_id'],
                'quantity' => $line['quantity'],
            ]));
        }

        return Recipe::query()
            ->with('ingredient')
            ->whereIn('id', $created->pluck('id'))
            ->get();
    }

    private function updateWeightedAverageCost(Ingredient $ingredient, float $incomingQty, float $incomingCost): void
    {
        $currentStock = (float) $ingredient->current_stock - $incomingQty;

        if ($currentStock <= 0) {
            $ingredient->update(['unit_cost' => $incomingCost]);

            return;
        }

        $currentValue = $currentStock * (float) $ingredient->unit_cost;
        $incomingValue = $incomingQty * $incomingCost;
        $newAverage = ($currentValue + $incomingValue) / ($currentStock + $incomingQty);

        $ingredient->update(['unit_cost' => round($newAverage, 4)]);
    }
}
