<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
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
        'refund' => 1,
        'transfer_in' => 1,
        'transfer_out' => -1,
        'production' => -1,
    ];

    public function __construct(private readonly IngredientService $ingredients) {}

    public function recordMovement(
        InventoryStock $stock,
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

        return DB::transaction(function () use ($stock, $type, $quantity, $user, $unitCost, $notes, $reference, $branchId, $adjustmentDirection): StockMovement {
            $stock = InventoryStock::query()->lockForUpdate()->findOrFail($stock->id);

            $signedQty = match ($type) {
                'adjustment' => $adjustmentDirection === 'out' ? -$quantity : $quantity,
                default => $quantity * self::DIRECTION[$type],
            };

            $newStock = (float) $stock->current_stock + $signedQty;

            if ($newStock < 0) {
                throw new RuntimeException("Insufficient stock for {$stock->name_ar}.");
            }

            $movement = StockMovement::create([
                'ingredient_id' => $stock->ingredient_id,
                'branch_id' => $branchId ?? $stock->branch_id,
                'user_id' => $user?->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $stock->unit_cost,
                'notes' => $notes,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
            ]);

            $stock->update(['current_stock' => $newStock]);

            if ($type === 'purchase' && $unitCost !== null) {
                $this->updateWeightedAverageCost($stock->fresh() ?? $stock, $quantity, $unitCost);
                $this->refreshMenuItemsCostForIngredient($stock->ingredient_id);
            }

            AuditLogger::log("inventory.{$type}", $movement, [
                'ingredient_id' => $stock->ingredient_id,
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
                if (! $orderItem->menu_item_id) {
                    continue;
                }
                $recipes = Recipe::query()
                    ->where('menu_item_id', $orderItem->menu_item_id)
                    ->with('ingredient')
                    ->get();

                foreach ($recipes as $recipe) {
                    $deductQty = (float) $recipe->quantity * (int) $orderItem->quantity;

                    if ($deductQty <= 0) {
                        continue;
                    }

                    $stockRow = $this->ingredients->stockAtBranch(
                        (int) $recipe->ingredient_id,
                        $order->branch_id,
                    );

                    if (! $stockRow) {
                        continue;
                    }

                    $this->recordMovement(
                        stock: $stockRow,
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

    public function restoreForOrder(Order $order, ?User $user = null): void
    {
        if (StockMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'refund')
            ->exists()) {
            return;
        }

        $saleMovements = StockMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'sale')
            ->get();

        if ($saleMovements->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($saleMovements, $order, $user): void {
            foreach ($saleMovements as $movement) {
                $stockRow = $this->ingredients->stockAtBranch(
                    (int) $movement->ingredient_id,
                    $movement->branch_id,
                );

                if (! $stockRow) {
                    continue;
                }

                $this->recordMovement(
                    stock: $stockRow,
                    type: 'refund',
                    quantity: (float) $movement->quantity,
                    user: $user,
                    reference: $order,
                    branchId: $movement->branch_id,
                    notes: "Refund for order #{$order->id}",
                );
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
                $stockRow = $this->ingredients->stockAtBranch(
                    (int) $line->ingredient_id,
                    $purchaseOrder->branch_id,
                );

                if (! $stockRow) {
                    $stockRow = $this->ingredients->openAtBranch([
                        'ingredient_id' => $line->ingredient_id,
                        'branch_id' => $purchaseOrder->branch_id,
                        'current_stock' => 0,
                        'unit_cost' => $line->unit_cost,
                    ]);
                }

                $this->recordMovement(
                    stock: $stockRow,
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
     * @return Collection<int, InventoryStock>
     */
    public function lowStockIngredients(?int $branchId = null): Collection
    {
        return InventoryStock::query()
            ->where('inventory_stocks.is_active', true)
            ->when($branchId, fn ($q, $id) => $q->where('inventory_stocks.branch_id', $id))
            ->whereColumn('inventory_stocks.current_stock', '<=', 'inventory_stocks.reorder_level')
            ->where('inventory_stocks.reorder_level', '>', 0)
            ->orderByName()
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
            'unit_cost' => $recipe->ingredient->default_cost,
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
            if (! Ingredient::query()->whereKey($line['ingredient_id'])->exists()) {
                throw new InvalidArgumentException('Ingredient not found.');
            }

            $created->push(Recipe::create([
                'menu_item_id' => $menuItem->id,
                'ingredient_id' => $line['ingredient_id'],
                'quantity' => $line['quantity'],
            ]));
        }

        $this->syncMenuItemCostPrice($menuItem->fresh());

        return Recipe::query()
            ->with('ingredient')
            ->whereIn('id', $created->pluck('id'))
            ->get();
    }

    private function syncMenuItemCostPrice(MenuItem $menuItem): void
    {
        $totalCost = round(
            Recipe::query()
                ->where('menu_item_id', $menuItem->id)
                ->with('ingredient')
                ->get()
                ->sum(fn (Recipe $recipe) => $recipe->lineCost()),
            2,
        );

        $menuItem->update(['cost_price' => $totalCost]);
    }

    private function refreshMenuItemsCostForIngredient(int $ingredientId): void
    {
        $menuItemIds = Recipe::query()
            ->where('ingredient_id', $ingredientId)
            ->distinct()
            ->pluck('menu_item_id');

        MenuItem::query()
            ->whereIn('id', $menuItemIds)
            ->get()
            ->each(fn (MenuItem $item) => $this->syncMenuItemCostPrice($item));
    }

    private function updateWeightedAverageCost(InventoryStock $stock, float $incomingQty, float $incomingCost): void
    {
        $currentStock = (float) $stock->current_stock - $incomingQty;

        if ($currentStock <= 0) {
            $stock->update(['unit_cost' => $incomingCost]);
        } else {
            $currentValue = $currentStock * (float) $stock->unit_cost;
            $incomingValue = $incomingQty * $incomingCost;
            $newAverage = ($currentValue + $incomingValue) / ($currentStock + $incomingQty);

            $stock->update(['unit_cost' => round($newAverage, 4)]);
        }

        $stock->refresh();
        $ingredient = $stock->ingredient;

        if ($ingredient instanceof Ingredient) {
            $ingredient->update(['default_cost' => $stock->unit_cost]);
        }
    }
}
