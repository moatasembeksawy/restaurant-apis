<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Http\Controllers;

use App\Modules\POS\Tables\Http\Requests\IndexTableRequest;
use App\Modules\POS\Tables\Http\Requests\StoreTableRequest;
use App\Modules\POS\Tables\Http\Requests\UpdateTableRequest;
use App\Modules\POS\Tables\Http\Requests\UpdateTableStatusRequest;
use App\Modules\POS\Tables\Http\Resources\FloorTableResource;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Floor Tables
 */
class TableController extends Controller
{
    public function index(IndexTableRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tables = FloorTable::query()
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['section'] ?? null, fn ($q, $s) => $q->where('section', $s))
            ->with('activeOrder')
            ->orderBy('section')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(FloorTableResource::collection($tables));
    }

    public function store(StoreTableRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $table = FloorTable::create($validated);

        return ApiResponse::created(new FloorTableResource($table), 'Table created.');
    }

    public function show(FloorTable $table): JsonResponse
    {
        return ApiResponse::success(new FloorTableResource($table->load('activeOrder.items')));
    }

    public function update(UpdateTableRequest $request, FloorTable $table): JsonResponse
    {
        $validated = $request->validated();

        $table->update($validated);

        return ApiResponse::success(new FloorTableResource($table), 'Table updated.');
    }

    public function destroy(FloorTable $table): JsonResponse
    {
        $table->delete();

        return ApiResponse::noContent();
    }

    public function updateStatus(UpdateTableStatusRequest $request, FloorTable $table): JsonResponse
    {
        $validated = $request->validated();

        $table->update($validated);

        return ApiResponse::success(new FloorTableResource($table), 'Table status updated.');
    }
}
