<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Print\Http\Requests\IndexPrintSettingsRequest;
use App\Modules\POS\Print\Http\Requests\ReplacePrintRoutesRequest;
use App\Modules\POS\Print\Http\Requests\UpdatePrintSettingsRequest;
use App\Modules\POS\Print\Http\Resources\PrintRouteResource;
use App\Modules\POS\Print\Models\KitchenStation;
use App\Modules\POS\Print\Models\Printer;
use App\Modules\POS\Print\Models\PrintRoute;
use App\Modules\POS\Print\Services\PrintConfigurationService;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Print Routing
 */
class PrintSettingsController extends Controller
{
    public function __construct(
        private readonly PrintConfigurationService $configuration,
    ) {}

    public function show(IndexPrintSettingsRequest $request): JsonResponse
    {
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));

        return ApiResponse::success([
            'branch_id' => $branch->id,
            'printing_mode' => $branch->printing_mode,
            'printers' => Printer::query()
                ->where('branch_id', $branch->id)
                ->orderBy('name')
                ->get(),
            'stations' => KitchenStation::query()
                ->where('branch_id', $branch->id)
                ->with('printers')
                ->orderBy('name')
                ->get(),
            'routes' => PrintRoute::query()
                ->where('branch_id', $branch->id)
                ->with(['category:id,name_ar,name_en', 'item:id,name_ar,name_en', 'printer', 'station.printers'])
                ->get(),
        ]);
    }

    public function update(UpdatePrintSettingsRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());
        AuditLogger::log('print_settings.updated', $branch, $request->validated());

        return ApiResponse::success([
            'branch_id' => $branch->id,
            'printing_mode' => $branch->printing_mode,
        ], 'Print settings updated.');
    }

    public function replaceCategoryRoutes(
        ReplacePrintRoutesRequest $request,
        MenuCategory $category,
    ): JsonResponse {
        return $this->replace($request, $category);
    }

    public function replaceItemRoutes(
        ReplacePrintRoutesRequest $request,
        MenuItem $item,
    ): JsonResponse {
        return $this->replace($request, $item);
    }

    private function replace(ReplacePrintRoutesRequest $request, MenuCategory|MenuItem $source): JsonResponse
    {
        $validated = $request->validated();

        try {
            $routes = $this->configuration->replaceRoutes(
                $source,
                (int) $validated['branch_id'],
                $validated['targets'],
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PRINT_ROUTING_FAILED', 422);
        }

        return ApiResponse::success(
            PrintRouteResource::collection($routes),
            'Print routes updated.',
        );
    }
}
