<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Controllers;

use App\Modules\POS\Print\Http\Requests\IndexPrintSettingsRequest;
use App\Modules\POS\Print\Http\Requests\StoreKitchenStationRequest;
use App\Modules\POS\Print\Http\Requests\UpdateKitchenStationRequest;
use App\Modules\POS\Print\Http\Resources\KitchenStationResource;
use App\Modules\POS\Print\Models\KitchenStation;
use App\Modules\POS\Print\Services\PrintConfigurationService;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Kitchen Stations
 */
class KitchenStationController extends Controller
{
    public function __construct(
        private readonly PrintConfigurationService $configuration,
    ) {}

    public function index(IndexPrintSettingsRequest $request): JsonResponse
    {
        $stations = KitchenStation::query()
            ->where('branch_id', $request->integer('branch_id'))
            ->with('printers')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(KitchenStationResource::collection($stations));
    }

    public function store(StoreKitchenStationRequest $request): JsonResponse
    {
        try {
            $station = $this->configuration->createStation($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'KITCHEN_STATION_CREATE_FAILED', 422);
        }

        return ApiResponse::created(new KitchenStationResource($station), 'Kitchen station created.');
    }

    public function update(UpdateKitchenStationRequest $request, KitchenStation $station): JsonResponse
    {
        try {
            $station = $this->configuration->updateStation($station, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'KITCHEN_STATION_UPDATE_FAILED', 422);
        }

        return ApiResponse::success(new KitchenStationResource($station), 'Kitchen station updated.');
    }

    public function destroy(KitchenStation $station): JsonResponse
    {
        AuditLogger::log('kitchen_station.deleted', $station);
        $station->delete();

        return ApiResponse::noContent();
    }
}
