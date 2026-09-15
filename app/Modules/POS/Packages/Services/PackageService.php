<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Services;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Packages\Models\MenuPackageSlot;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PackageService
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MenuPackage
    {
        $this->planLimits->check('packages');

        return DB::transaction(function () use ($data): MenuPackage {
            /** @var list<array<string, mixed>> $slots */
            $slots = $data['slots'] ?? [];
            unset($data['slots']);

            $package = MenuPackage::create($data);
            $this->syncSlots($package, $slots);

            return $package->fresh(['slots.options.menuItem', 'category']) ?? $package;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MenuPackage $package, array $data): MenuPackage
    {
        return DB::transaction(function () use ($package, $data): MenuPackage {
            /** @var list<array<string, mixed>>|null $slots */
            $slots = array_key_exists('slots', $data) ? $data['slots'] : null;
            unset($data['slots']);

            $package->update($data);

            if (is_array($slots)) {
                foreach ($package->slots()->with('options')->get() as $slot) {
                    $slot->options()->delete();
                    $slot->delete();
                }
                $this->syncSlots($package, $slots);
            }

            return $package->fresh(['slots.options.menuItem', 'category']) ?? $package;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    public function syncSlots(MenuPackage $package, array $slots): void
    {
        if ($slots === []) {
            throw new InvalidArgumentException('A package must include at least one slot.');
        }

        foreach ($slots as $index => $slotData) {
            $type = (string) ($slotData['type'] ?? '');

            if (! in_array($type, [MenuPackageSlot::TYPE_FIXED, MenuPackageSlot::TYPE_CHOICE], true)) {
                throw new InvalidArgumentException('Invalid package slot type.');
            }

            if ($type === MenuPackageSlot::TYPE_FIXED) {
                $menuItemId = (int) ($slotData['menu_item_id'] ?? 0);
                $this->assertMenuItem($menuItemId);

                $package->slots()->create([
                    'type' => $type,
                    'menu_item_id' => $menuItemId,
                    'quantity' => (int) ($slotData['quantity'] ?? 1),
                    'name_ar' => $slotData['name_ar'] ?? null,
                    'name_en' => $slotData['name_en'] ?? null,
                    'min_select' => 1,
                    'max_select' => 1,
                    'sort_order' => (int) ($slotData['sort_order'] ?? $index),
                ]);

                continue;
            }

            /** @var list<array<string, mixed>> $options */
            $options = $slotData['options'] ?? [];

            if ($options === []) {
                throw new InvalidArgumentException('Choice slots require at least one menu item option.');
            }

            $minSelect = (int) ($slotData['min_select'] ?? 1);
            $maxSelect = (int) ($slotData['max_select'] ?? $minSelect);

            if ($minSelect < 1 || $maxSelect < $minSelect) {
                throw new InvalidArgumentException('Choice slot min/max select is invalid.');
            }

            if (count($options) < $minSelect) {
                throw new InvalidArgumentException('Choice slot needs at least as many options as min_select.');
            }

            $slot = $package->slots()->create([
                'type' => $type,
                'menu_item_id' => null,
                'quantity' => (int) ($slotData['quantity'] ?? 1),
                'name_ar' => $slotData['name_ar'] ?? 'اختيار',
                'name_en' => $slotData['name_en'] ?? null,
                'min_select' => $minSelect,
                'max_select' => $maxSelect,
                'sort_order' => (int) ($slotData['sort_order'] ?? $index),
            ]);

            foreach ($options as $optionIndex => $option) {
                $menuItemId = (int) ($option['menu_item_id'] ?? 0);
                $this->assertMenuItem($menuItemId);

                $slot->options()->create([
                    'menu_item_id' => $menuItemId,
                    'extra_price' => round((float) ($option['extra_price'] ?? 0), 2),
                    'is_available' => (bool) ($option['is_available'] ?? true),
                    'sort_order' => (int) ($option['sort_order'] ?? $optionIndex),
                ]);
            }
        }
    }

    private function assertMenuItem(int $menuItemId): void
    {
        if ($menuItemId < 1 || MenuItem::query()->whereKey($menuItemId)->doesntExist()) {
            throw new InvalidArgumentException('Menu item not found for package slot.');
        }
    }
}
