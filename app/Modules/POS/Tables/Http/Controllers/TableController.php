<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Http\Controllers;

use App\Modules\POS\Tables\Models\FloorTable;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @group Floor Tables
 */
class TableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tables = FloorTable::query()
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('section'), fn ($q, $s) => $q->where('section', $s))
            ->with('activeOrder')
            ->orderBy('section')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($tables);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'capacity' => ['integer', 'min:1', 'max:50'],
            'position_x' => ['integer', 'min:0'],
            'position_y' => ['integer', 'min:0'],
        ]);

        $table = FloorTable::create($validated);

        return ApiResponse::created($table, 'Table created.');
    }

    public function show(FloorTable $table): JsonResponse
    {
        return ApiResponse::success($table->load('activeOrder.items'));
    }

    public function update(Request $request, FloorTable $table): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'position_x' => ['sometimes', 'integer', 'min:0'],
            'position_y' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $table->update($validated);

        return ApiResponse::success($table, 'Table updated.');
    }

    public function destroy(FloorTable $table): JsonResponse
    {
        $table->delete();

        return ApiResponse::noContent();
    }

    public function updateStatus(Request $request, FloorTable $table): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:free,occupied,reserved,unavailable'],
        ]);

        $table->update($validated);

        return ApiResponse::success($table, 'Table status updated.');
    }
}
