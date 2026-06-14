<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('subscription', [\App\Modules\Tenant\Http\Controllers\SubscriptionController::class, 'show']);
Route::post('subscription/upgrade', [\App\Modules\Tenant\Http\Controllers\SubscriptionController::class, 'upgrade']);
Route::get('branches', [\App\Modules\Tenant\Http\Controllers\BranchController::class, 'index']);
Route::post('branches', [\App\Modules\Tenant\Http\Controllers\BranchController::class, 'store']);
Route::patch('branches/{branch}', [\App\Modules\Tenant\Http\Controllers\BranchController::class, 'update']);
Route::get('audit-log', [\App\Modules\Tenant\Http\Controllers\AuditLogController::class, 'index']);
