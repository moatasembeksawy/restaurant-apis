<?php

declare(strict_types=1);

use App\Modules\Tenant\Http\Controllers\AuditLogController;
use App\Modules\Tenant\Http\Controllers\BranchController;
use App\Modules\Tenant\Http\Controllers\StaffController;
use App\Modules\Tenant\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('subscription', [SubscriptionController::class, 'show']);
Route::post('subscription/upgrade', [SubscriptionController::class, 'upgrade']);
Route::get('branches', [BranchController::class, 'index']);
Route::post('branches', [BranchController::class, 'store']);
Route::patch('branches/{branch}', [BranchController::class, 'update']);
Route::get('audit-log', [AuditLogController::class, 'index'])
    ->middleware('feature:audit_log');

Route::middleware('feature:staff_shifts')->group(function (): void {
    Route::get('staff/shifts', [\App\Modules\Tenant\Staff\Http\Controllers\StaffShiftController::class, 'index']);
    Route::get('staff/shifts/active', [\App\Modules\Tenant\Staff\Http\Controllers\StaffShiftController::class, 'active']);
    Route::post('staff/shifts/clock-in', [\App\Modules\Tenant\Staff\Http\Controllers\StaffShiftController::class, 'clockIn']);
    Route::post('staff/shifts/clock-out', [\App\Modules\Tenant\Staff\Http\Controllers\StaffShiftController::class, 'clockOut']);
});

Route::get('staff', [StaffController::class, 'index']);
Route::post('staff', [StaffController::class, 'store']);
Route::get('staff/{staff}', [StaffController::class, 'show']);
Route::patch('staff/{staff}', [StaffController::class, 'update']);
Route::post('staff/{staff}/deactivate', [StaffController::class, 'deactivate']);

Route::get('settings/eta', [\App\Modules\Tenant\Http\Controllers\ETASettingsController::class, 'show']);
Route::patch('settings/eta', [\App\Modules\Tenant\Http\Controllers\ETASettingsController::class, 'update']);

Route::get('ops/health', [\App\Modules\Tenant\Http\Controllers\OpsController::class, 'health']);

Route::middleware('feature:multi_branch')->group(function (): void {
    Route::get('reports/branches/compare', [\App\Modules\Tenant\Reports\Http\Controllers\BranchReportController::class, 'compare']);
});
