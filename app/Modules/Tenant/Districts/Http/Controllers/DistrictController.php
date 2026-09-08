<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Http\Controllers;

use App\Modules\Tenant\Districts\Http\Requests\IndexDistrictRequest;
use App\Modules\Tenant\Districts\Http\Requests\StoreDistrictRequest;
use App\Modules\Tenant\Districts\Http\Requests\UpdateDistrictRequest;
use App\Modules\Tenant\Districts\Http\Resources\DistrictResource;
use App\Modules\Tenant\Districts\Models\District;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * @group Districts
 */
class DistrictController extends Controller
{
    public function index(IndexDistrictRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $districts = District::query()
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(DistrictResource::collection($districts));
    }

    public function store(StoreDistrictRequest $request): JsonResponse
    {
        $this->authorizeDistrictManagement($request);

        $district = District::create($request->validated());
        $district->refresh();

        AuditLogger::log('district.created', $district);

        return ApiResponse::created(new DistrictResource($district), 'District created.');
    }

    public function update(UpdateDistrictRequest $request, District $district): JsonResponse
    {
        $this->authorizeDistrictManagement($request);

        $district->update($request->validated());

        AuditLogger::log('district.updated', $district);

        return ApiResponse::success(new DistrictResource($district), 'District updated.');
    }

    public function destroy(Request $request, District $district): Response
    {
        $this->authorizeDistrictManagement($request);

        AuditLogger::log('district.deleted', $district);
        $district->delete();

        return ApiResponse::noContent();
    }

    private function authorizeDistrictManagement(Request $request): void
    {
        if (! in_array($request->user()->role, ['owner', 'manager'], true)) {
            throw new HttpResponseException(
                ApiResponse::error('Only owners or managers can manage districts.', 'FORBIDDEN', 403),
            );
        }
    }
}
