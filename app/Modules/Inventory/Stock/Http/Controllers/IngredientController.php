<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Modules\Inventory\Stock\Http\Requests\IndexIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\StoreIngredientRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateIngredientRequest;
use App\Modules\Inventory\Stock\Http\Resources\IngredientResource;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Services\IngredientService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Inventory — Ingredients
 */
class IngredientController extends Controller
{
    public function __construct(private readonly IngredientService $ingredients) {}

    public function index(IndexIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $items = Ingredient::query()
            ->with([
                'stocks' => fn ($q) => $q->orderBy('branch_id')->with('branch:id,name,name_ar'),
            ])
            ->when($validated['active'] ?? null, fn ($q) => $q->where('is_active', true))
            ->orderBy('name_ar')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated($items, IngredientResource::class);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            if (($validated['branch_id'] ?? null) !== null) {
                $stock = $this->ingredients->openAtBranch($validated);
                $ingredient = $stock->ingredient()->with([
                    'stocks' => fn ($q) => $q->orderBy('branch_id')->with('branch:id,name,name_ar'),
                ])->firstOrFail();
            } else {
                $ingredient = $this->ingredients->findOrCreate(
                    ingredientId: isset($validated['ingredient_id']) ? (int) $validated['ingredient_id'] : null,
                    nameAr: $validated['name_ar'] ?? null,
                    nameEn: $validated['name_en'] ?? null,
                    unit: $validated['unit'] ?? null,
                    defaultCost: isset($validated['default_cost'])
                        ? (float) $validated['default_cost']
                        : (isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null),
                )->load([
                    'stocks' => fn ($q) => $q->orderBy('branch_id')->with('branch:id,name,name_ar'),
                ]);
            }
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'INGREDIENT_ERROR', 422);
        }

        return ApiResponse::created(new IngredientResource($ingredient), 'Ingredient created.');
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        $ingredient->load([
            'stocks' => fn ($q) => $q->orderBy('branch_id')->with('branch:id,name,name_ar'),
        ]);

        return ApiResponse::success(new IngredientResource($ingredient));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $ingredient = $this->ingredients->updateMaster($ingredient, $request->validated());
        $ingredient->load([
            'stocks' => fn ($q) => $q->orderBy('branch_id')->with('branch:id,name,name_ar'),
        ]);

        return ApiResponse::success(new IngredientResource($ingredient), 'Ingredient updated.');
    }
}
