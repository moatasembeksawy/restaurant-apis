<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Controllers;

use App\Modules\Tenant\Finance\Http\Requests\IndexExpenseRequest;
use App\Modules\Tenant\Finance\Http\Requests\StoreExpenseRequest;
use App\Modules\Tenant\Finance\Http\Requests\VoidExpenseRequest;
use App\Modules\Tenant\Finance\Http\Resources\ExpenseResource;
use App\Modules\Tenant\Finance\Models\Expense;
use App\Modules\Tenant\Finance\Services\ExpenseService;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Expenses
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    public function index(IndexExpenseRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = $this->filteredQuery($filters)
            ->with(['branch:id,name', 'category:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->latest('expense_date')
            ->latest('id');

        return ApiResponse::paginated(
            $query->paginate((int) ($filters['per_page'] ?? 25)),
            ExpenseResource::class,
        );
    }

    public function summary(IndexExpenseRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['status'], $filters['per_page']);

        $query = $this->filteredQuery($filters)->where('status', 'approved');

        $byCategory = (clone $query)
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->selectRaw('expense_categories.id, expense_categories.name, COUNT(*) as count, SUM(expenses.amount) as total')
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'category_id' => $row->id,
                'category_name' => $row->name,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ])
            ->all();

        $byPaymentMethod = (clone $query)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->payment_method => [
                    'count' => (int) $row->count,
                    'total' => round((float) $row->total, 2),
                ],
            ])
            ->all();

        return ApiResponse::success([
            'expenses_count' => (clone $query)->count(),
            'total_expenses' => round((float) (clone $query)->sum('amount'), 2),
            'by_category' => $byCategory,
            'by_payment_method' => $byPaymentMethod,
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        try {
            $expense = $this->expenses->create($request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'EXPENSE_CREATE_FAILED', 422);
        }

        return ApiResponse::created(new ExpenseResource($expense), 'Expense submitted for approval.');
    }

    public function show(Expense $expense): JsonResponse
    {
        return ApiResponse::success(new ExpenseResource($expense->load([
            'branch:id,name',
            'category:id,name,code',
            'shift',
            'cashMovement',
            'creator:id,name',
            'approver:id,name',
        ])));
    }

    public function approve(Request $request, Expense $expense): JsonResponse
    {
        try {
            $expense = $this->expenses->approve($expense, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'EXPENSE_APPROVAL_FAILED', 422);
        }

        return ApiResponse::success(new ExpenseResource($expense), 'Expense approved.');
    }

    public function void(VoidExpenseRequest $request, Expense $expense): JsonResponse
    {
        try {
            $expense = $this->expenses->void(
                $expense,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'EXPENSE_VOID_FAILED', 422);
        }

        return ApiResponse::success(new ExpenseResource($expense), 'Expense voided.');
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = Expense::query();

        foreach (['branch_id', 'expense_category_id', 'status', 'payment_method'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (isset($filters['from'])) {
            $query->whereDate('expense_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('expense_date', '<=', $filters['to']);
        }

        return $query;
    }
}
