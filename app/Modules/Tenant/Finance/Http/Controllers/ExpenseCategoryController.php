<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Controllers;

use App\Modules\Tenant\Finance\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Tenant\Finance\Http\Requests\UpdateExpenseCategoryRequest;
use App\Modules\Tenant\Finance\Http\Resources\ExpenseCategoryResource;
use App\Modules\Tenant\Finance\Models\ExpenseCategory;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @group Expense Categories
 */
class ExpenseCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ExpenseCategoryResource::collection(
                ExpenseCategory::query()->orderBy('name')->get(),
            ),
        );
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = ExpenseCategory::create($request->validated());

        AuditLogger::log('expense_category.created', $category);

        return ApiResponse::created(new ExpenseCategoryResource($category), 'Expense category created.');
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $expenseCategory->update($request->validated());

        AuditLogger::log('expense_category.updated', $expenseCategory, [
            'allocates_to_item_cost' => $expenseCategory->allocates_to_item_cost,
        ]);

        return ApiResponse::success(new ExpenseCategoryResource($expenseCategory), 'Expense category updated.');
    }
}
