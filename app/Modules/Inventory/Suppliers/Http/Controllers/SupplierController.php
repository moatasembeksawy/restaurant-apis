<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Controllers;

use App\Modules\Inventory\Suppliers\Http\Requests\StoreSupplierRequest;
use App\Modules\Inventory\Suppliers\Http\Requests\UpdateSupplierRequest;
use App\Modules\Inventory\Suppliers\Http\Resources\SupplierResource;
use App\Modules\Inventory\Suppliers\Models\Supplier;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Inventory — Suppliers
 */
class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return ApiResponse::success(SupplierResource::collection($suppliers));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return ApiResponse::created(new SupplierResource($supplier), 'Supplier created.');
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return ApiResponse::success(new SupplierResource($supplier), 'Supplier updated.');
    }
}
