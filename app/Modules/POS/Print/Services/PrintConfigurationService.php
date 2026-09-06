<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Services;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Print\Models\KitchenStation;
use App\Modules\POS\Print\Models\Printer;
use App\Modules\POS\Print\Models\PrintRoute;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PrintConfigurationService
{
    /** @param array<string, mixed> $data */
    public function createPrinter(array $data): Printer
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $this->ensureUniqueBridgeKey($branch->id, $data['bridge_key']);
        $this->validateDefaultTypes($data);

        return DB::transaction(function () use ($data, $branch): Printer {
            $printer = Printer::create([
                ...$data,
                'paper_width' => $data['paper_width'] ?? 80,
                'copies' => $data['copies'] ?? 1,
                'auto_print' => $data['auto_print'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->clearOtherDefaults($printer);
            AuditLogger::log('printer.created', $printer, ['branch_id' => $branch->id]);

            return $printer->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePrinter(Printer $printer, array $data): Printer
    {
        if (isset($data['bridge_key']) && $data['bridge_key'] !== $printer->bridge_key) {
            $this->ensureUniqueBridgeKey($printer->branch_id, $data['bridge_key'], $printer->id);
        }
        $this->validateDefaultTypes([
            ...$printer->only(['type', 'is_default_kitchen', 'is_default_receipt']),
            ...$data,
        ]);

        return DB::transaction(function () use ($printer, $data): Printer {
            $printer->update($data);
            $this->clearOtherDefaults($printer);
            AuditLogger::log('printer.updated', $printer);

            return $printer->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createStation(array $data): KitchenStation
    {
        Branch::query()->findOrFail($data['branch_id']);
        if (KitchenStation::query()
            ->where('branch_id', $data['branch_id'])
            ->where('name', $data['name'])
            ->exists()) {
            throw new InvalidArgumentException('Station name must be unique within the branch.');
        }
        $printerIds = $data['printer_ids'] ?? [];
        unset($data['printer_ids']);

        $this->validateStationPrinters((int) $data['branch_id'], $printerIds);

        return DB::transaction(function () use ($data, $printerIds): KitchenStation {
            $station = KitchenStation::create([
                ...$data,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $station->printers()->sync($printerIds);
            AuditLogger::log('kitchen_station.created', $station);

            return $station->load('printers');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStation(KitchenStation $station, array $data): KitchenStation
    {
        if (isset($data['name']) && KitchenStation::query()
            ->where('branch_id', $station->branch_id)
            ->where('name', $data['name'])
            ->whereKeyNot($station->id)
            ->exists()) {
            throw new InvalidArgumentException('Station name must be unique within the branch.');
        }
        $printerIds = $data['printer_ids'] ?? null;
        unset($data['printer_ids']);

        if ($printerIds !== null) {
            $this->validateStationPrinters($station->branch_id, $printerIds);
        }

        return DB::transaction(function () use ($station, $data, $printerIds): KitchenStation {
            $station->update($data);
            if ($printerIds !== null) {
                $station->printers()->sync($printerIds);
            }
            AuditLogger::log('kitchen_station.updated', $station);

            return $station->fresh()->load('printers');
        });
    }

    /**
     * @param  MenuCategory|MenuItem  $source
     * @param  list<array<string, int|null>>  $targets
     * @return Collection<int, PrintRoute>
     */
    public function replaceRoutes(Model $source, int $branchId, array $targets): Collection
    {
        Branch::query()->findOrFail($branchId);
        $this->validateTargets($branchId, $targets);
        $sourceBranchId = $source instanceof MenuItem
            ? $source->category?->branch_id
            : $source->branch_id;

        if ($sourceBranchId !== null && (int) $sourceBranchId !== $branchId) {
            throw new InvalidArgumentException('The menu source belongs to another branch.');
        }

        $sourceColumn = $source instanceof MenuItem ? 'menu_item_id' : 'menu_category_id';

        return DB::transaction(function () use ($source, $sourceColumn, $branchId, $targets): Collection {
            PrintRoute::query()
                ->where('branch_id', $branchId)
                ->where($sourceColumn, $source->id)
                ->delete();

            foreach ($targets as $target) {
                PrintRoute::create([
                    'branch_id' => $branchId,
                    $sourceColumn => $source->id,
                    'printer_id' => $target['printer_id'] ?? null,
                    'kitchen_station_id' => $target['kitchen_station_id'] ?? null,
                ]);
            }

            AuditLogger::log('print_routes.replaced', $source, [
                'branch_id' => $branchId,
                'target_count' => count($targets),
            ]);

            return PrintRoute::query()
                ->where('branch_id', $branchId)
                ->where($sourceColumn, $source->id)
                ->with(['printer', 'station.printers'])
                ->get();
        });
    }

    private function clearOtherDefaults(Printer $printer): void
    {
        foreach (['is_default_kitchen', 'is_default_receipt'] as $field) {
            if ($printer->{$field}) {
                Printer::query()
                    ->where('branch_id', $printer->branch_id)
                    ->whereKeyNot($printer->id)
                    ->update([$field => false]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateDefaultTypes(array $data): void
    {
        $type = $data['type'];

        if (($data['is_default_kitchen'] ?? false) && ! in_array($type, ['kitchen', 'both'], true)) {
            throw new InvalidArgumentException('The default kitchen printer must support kitchen tickets.');
        }

        if (($data['is_default_receipt'] ?? false) && ! in_array($type, ['receipt', 'both'], true)) {
            throw new InvalidArgumentException('The default receipt printer must support receipts.');
        }
    }

    private function ensureUniqueBridgeKey(int $branchId, string $key, ?int $exceptId = null): void
    {
        $exists = Printer::query()
            ->where('branch_id', $branchId)
            ->where('bridge_key', $key)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('bridge_key must be unique within the branch.');
        }
    }

    /** @param list<int> $printerIds */
    private function validateStationPrinters(int $branchId, array $printerIds): void
    {
        $count = Printer::query()
            ->where('branch_id', $branchId)
            ->whereIn('type', ['kitchen', 'both'])
            ->whereIn('id', $printerIds)
            ->count();

        if ($count !== count(array_unique($printerIds))) {
            throw new InvalidArgumentException('Every station printer must be a kitchen printer in the same branch.');
        }
    }

    /** @param list<array<string, int|null>> $targets */
    private function validateTargets(int $branchId, array $targets): void
    {
        $printingMode = Branch::query()->whereKey($branchId)->value('printing_mode');

        foreach ($targets as $target) {
            if (isset($target['printer_id'])) {
                $valid = Printer::query()
                    ->where('branch_id', $branchId)
                    ->whereIn('type', ['kitchen', 'both'])
                    ->whereKey($target['printer_id'])
                    ->exists();
            } else {
                if ($printingMode !== 'stations') {
                    throw new InvalidArgumentException('Switch the branch printing_mode to stations before using station routes.');
                }

                $valid = KitchenStation::query()
                    ->where('branch_id', $branchId)
                    ->whereKey($target['kitchen_station_id'])
                    ->exists();
            }

            if (! $valid) {
                throw new InvalidArgumentException('Every print target must belong to the selected branch.');
            }
        }
    }
}
