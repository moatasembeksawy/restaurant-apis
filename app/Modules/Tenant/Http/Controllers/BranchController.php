<?php
declare(strict_types=1);
namespace App\Modules\Tenant\Http\Controllers;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
class BranchController extends Controller
{
    public function index(): JsonResponse { return ApiResponse::success(Branch::all()); }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name'=>['required','string'],'name_ar'=>['required','string'],'address'=>['nullable','string'],'phone'=>['nullable','string'],'timezone'=>['nullable','string']]);
        return ApiResponse::created(Branch::create($data), 'Branch created.');
    }
    public function update(Request $request, Branch $branch): JsonResponse
    {
        $branch->update($request->only(['name','name_ar','address','phone','is_active']));
        return ApiResponse::success($branch, 'Branch updated.');
    }
}
