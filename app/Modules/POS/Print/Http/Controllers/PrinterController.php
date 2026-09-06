<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Controllers;

use App\Modules\POS\Print\Http\Requests\IndexPrintSettingsRequest;
use App\Modules\POS\Print\Http\Requests\StorePrinterRequest;
use App\Modules\POS\Print\Http\Requests\UpdatePrinterRequest;
use App\Modules\POS\Print\Http\Resources\PrinterResource;
use App\Modules\POS\Print\Models\Printer;
use App\Modules\POS\Print\Services\PrintConfigurationService;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Printer Settings
 */
class PrinterController extends Controller
{
    public function __construct(
        private readonly PrintConfigurationService $configuration,
    ) {}

    public function index(IndexPrintSettingsRequest $request): JsonResponse
    {
        $printers = Printer::query()
            ->where('branch_id', $request->integer('branch_id'))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(PrinterResource::collection($printers));
    }

    public function store(StorePrinterRequest $request): JsonResponse
    {
        try {
            $printer = $this->configuration->createPrinter($request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PRINTER_CREATE_FAILED', 422);
        }

        return ApiResponse::created(new PrinterResource($printer), 'Printer created.');
    }

    public function update(UpdatePrinterRequest $request, Printer $printer): JsonResponse
    {
        try {
            $printer = $this->configuration->updatePrinter($printer, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PRINTER_UPDATE_FAILED', 422);
        }

        return ApiResponse::success(new PrinterResource($printer), 'Printer updated.');
    }

    public function destroy(Printer $printer): JsonResponse
    {
        AuditLogger::log('printer.deleted', $printer);
        $printer->delete();

        return ApiResponse::noContent();
    }
}
