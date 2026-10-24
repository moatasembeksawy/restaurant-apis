<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Services;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Services\ItemPartExpansionService;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Services\PackageExpansionService;
use InvalidArgumentException;

class OrderLineService
{
    public function __construct(
        private readonly PackageExpansionService $packages,
        private readonly ItemPartExpansionService $parts,
    ) {}

    /**
     * @param  array<string, mixed>  $line
     */
    public function add(Order $order, array $line): OrderItem
    {
        if (! empty($line['package_id'])) {
            return $this->packages->addToOrder($order, $line);
        }

        $menuItemId = (int) ($line['menu_item_id'] ?? 0);
        $menuItem = MenuItem::query()->with([
            'parts.options.menuItem',
            'parts.options.ingredient',
            'parts.defaultMenuItem',
            'parts.ingredient',
        ])->find($menuItemId);

        if (! $menuItem instanceof MenuItem) {
            throw new InvalidArgumentException('Menu item not found.');
        }

        if ($menuItem->hasParts()) {
            return $this->parts->addToOrder($order, $menuItem, $line);
        }

        $hasSoldPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null;

        if (! $menuItem->is_available && ! $hasSoldPrice) {
            throw new InvalidArgumentException(__('Menu item :menu_item_id is unavailable.', ['menu_item_id' => $menuItem->id]));
        }

        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $unitPrice = $hasSoldPrice
            ? round((float) $line['unit_price'], 2)
            : round((float) $menuItem->price, 2);

        if ($unitPrice < 0) {
            throw new InvalidArgumentException('Item price cannot be negative.');
        }

        return $order->items()->create([
            'line_type' => OrderItem::LINE_ITEM,
            'menu_item_id' => $menuItem->id,
            'package_id' => null,
            'parent_id' => null,
            'item_name_ar' => $menuItem->name_ar,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => round($unitPrice * $quantity, 2),
            'status' => 'pending',
            'notes' => $line['notes'] ?? null,
            'client_item_id' => $line['client_item_id'] ?? null,
        ]);
    }

    public function update(Order $order, OrderItem $item, array $data): OrderItem
    {
        if ($item->order_id !== $order->id) {
            throw new InvalidArgumentException('Item does not belong to this order.');
        }

        if ($item->isComponent()) {
            if (array_key_exists('quantity', $data) && (int) $data['quantity'] !== (int) $item->quantity) {
                throw new InvalidArgumentException('Components cannot change quantity independently.');
            }

            if (array_key_exists('notes', $data)) {
                $item->update(['notes' => $data['notes']]);
            }

            return $item->fresh() ?? $item;
        }

        $quantity = (int) ($data['quantity'] ?? $item->quantity);
        $previousQuantity = (int) $item->quantity;

        $attributes = [
            'quantity' => $quantity,
            'subtotal' => round((float) $item->unit_price * $quantity, 2),
        ];

        if (array_key_exists('notes', $data)) {
            $attributes['notes'] = $data['notes'];
        }

        if ($item->status === 'ready' && $quantity > $previousQuantity && ! $item->isPackageParent()) {
            $attributes['status'] = 'pending';
            $attributes['cooked_at'] = null;
        }

        $item->update($attributes);

        if ($previousQuantity > 0 && $quantity !== $previousQuantity) {
            $item->load('children');
            foreach ($item->children as $child) {
                $base = (int) $child->quantity / $previousQuantity;
                $child->update(['quantity' => (int) round($base * $quantity)]);
            }
        }

        return $item->fresh() ?? $item;
    }

    public function remove(Order $order, OrderItem $item): void
    {
        if ($item->order_id !== $order->id) {
            throw new InvalidArgumentException('Item does not belong to this order.');
        }

        if ($item->isComponent()) {
            throw new InvalidArgumentException('Cannot remove a component. Remove the parent item instead.');
        }

        $item->children()->delete();
        $item->delete();
    }
}
