<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Services\StockService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

/**
 * @group Inventory — Stock Movements
 */
class StockMovementController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $movements = \App\Modules\Inventory\Stock\Models\StockMovement::query()
            ->with(['ingredient:id,name_ar,unit', 'user:id,name'])
            ->when($request->query('ingredient_id'), fn ($q, $id) => $q->where('ingredient_id', $id))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', '50'));

        return ApiResponse::success($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer'],
            'type' => ['required', 'in:purchase,waste,adjustment'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:in,out'],
        ]);

        if ($validated['type'] === 'waste' && ! app('tenant')->hasFeature('waste_log')) {
            return ApiResponse::error('Waste logging requires Pro plan.', 'FEATURE_NOT_AVAILABLE', 402);
        }

        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        try {
            $movement = $this->stock->recordMovement(
                ingredient: $ingredient,
                type: $validated['type'],
                quantity: (float) $validated['quantity'],
                user: $request->user(),
                unitCost: isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null,
                notes: $validated['notes'] ?? null,
                adjustmentDirection: $validated['direction'] ?? 'in',
            );

            return ApiResponse::created([
                'movement' => $movement,
                'ingredient' => $ingredient->fresh(),
            ], 'Movement recorded.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_ERROR', 422);
        }
    }
}
