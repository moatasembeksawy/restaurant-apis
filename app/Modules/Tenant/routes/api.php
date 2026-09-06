<?php

declare(strict_types=1);

use App\Modules\Tenant\Finance\Http\Controllers\CashMovementController;
use App\Modules\Tenant\Finance\Http\Controllers\ExpenseCategoryController;
use App\Modules\Tenant\Finance\Http\Controllers\ExpenseController;
use App\Modules\Tenant\Http\Controllers\AuditLogController;
use App\Modules\Tenant\Http\Controllers\BranchController;
use App\Modules\Tenant\Http\Controllers\ETASettingsController;
use App\Modules\Tenant\Http\Controllers\OpsController;
use App\Modules\Tenant\Http\Controllers\StaffController;
use App\Modules\Tenant\Http\Controllers\SubscriptionController;
use App\Modules\Tenant\Http\Controllers\TenantSettingsController;
use App\Modules\Tenant\Reports\Http\Controllers\BranchReportController;
use App\Modules\Tenant\Staff\Http\Controllers\StaffShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:subscription.view')->get('subscription', [SubscriptionController::class, 'show']);
Route::middleware('permission:subscription.manage')->group(function (): void {
    Route::post('subscription/upgrade', [SubscriptionController::class, 'upgrade']);
    Route::post('subscription/downgrade', [SubscriptionController::class, 'downgrade']);
    Route::post('subscription/downgrade/cancel', [SubscriptionController::class, 'cancelPendingDowngrade']);
});
Route::middleware('permission:subscription.cancel')->group(function (): void {
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('subscription/resume', [SubscriptionController::class, 'resume']);
});
Route::middleware('permission:branches.view')->get('branches', [BranchController::class, 'index']);
Route::middleware('permission:branches.manage')->group(function (): void {
    Route::post('branches', [BranchController::class, 'store']);
    Route::patch('branches/{branch}', [BranchController::class, 'update']);
});
Route::get('audit-log', [AuditLogController::class, 'index'])
    ->middleware(['feature:audit_log', 'permission:audit.view']);

Route::middleware('feature:staff_shifts')->group(function (): void {
    Route::middleware('permission:shifts.view')->group(function (): void {
        Route::get('staff/shifts', [StaffShiftController::class, 'index']);
        Route::get('staff/shifts/active', [StaffShiftController::class, 'active']);
        Route::get('staff/shifts/current', [StaffShiftController::class, 'current']);
        Route::get('staff/shifts/{shift}', [StaffShiftController::class, 'show']);
    });
    Route::middleware('permission:shifts.operate')->group(function (): void {
        Route::post('staff/shifts/clock-in', [StaffShiftController::class, 'clockIn']);
        Route::post('staff/shifts/clock-out', [StaffShiftController::class, 'clockOut']);
    });
    Route::get('staff/shifts/{shift}/cash-movements', [CashMovementController::class, 'index'])
        ->middleware('permission:cash_movements.view');
    Route::post('staff/shifts/{shift}/cash-movements', [CashMovementController::class, 'store'])
        ->middleware('permission:cash_movements.create');
    Route::post('cash-movements/{movement}/reverse', [CashMovementController::class, 'reverse'])
        ->middleware('permission:cash_movements.reverse');
});

Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])
    ->middleware('permission:expenses.view');
Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])
    ->middleware('permission:expense_categories.manage');
Route::middleware('permission:expenses.view')->group(function (): void {
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
});
Route::post('expenses', [ExpenseController::class, 'store'])
    ->middleware('permission:expenses.create');
Route::middleware('permission:expenses.approve')->group(function (): void {
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void']);
});

Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view');
Route::middleware('permission:staff.manage')->group(function (): void {
    Route::post('staff', [StaffController::class, 'store']);
    Route::get('staff/{staff}', [StaffController::class, 'show']);
    Route::patch('staff/{staff}', [StaffController::class, 'update']);
    Route::post('staff/{staff}/deactivate', [StaffController::class, 'deactivate']);
});

Route::get('settings', [TenantSettingsController::class, 'show']);
Route::patch('settings', [TenantSettingsController::class, 'update']);
Route::get('settings/domain', [TenantSettingsController::class, 'domainStatus']);
Route::post('settings/domain/verify', [TenantSettingsController::class, 'verifyDomain']);

Route::get('settings/eta', [ETASettingsController::class, 'show']);
Route::patch('settings/eta', [ETASettingsController::class, 'update']);
Route::post('settings/eta/certificate', [ETASettingsController::class, 'uploadCertificate']);
Route::delete('settings/eta/certificate', [ETASettingsController::class, 'deleteCertificate']);

Route::get('ops/health', [OpsController::class, 'health']);

Route::middleware('feature:multi_branch')->group(function (): void {
    Route::get('reports/branches/compare', [BranchReportController::class, 'compare'])
        ->middleware('permission:reports.view');
});
