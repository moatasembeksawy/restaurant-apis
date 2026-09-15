<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Http\Controllers;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offers\Http\Requests\IndexOfferRequest;
use App\Modules\POS\Offers\Http\Requests\PreviewOfferRequest;
use App\Modules\POS\Offers\Http\Requests\StoreOfferRequest;
use App\Modules\POS\Offers\Http\Requests\UpdateOfferRequest;
use App\Modules\POS\Offers\Http\Resources\OfferResource;
use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Offers\Services\OfferEngine;
use App\Modules\POS\Offers\Services\OfferService;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Shared\Support\Http\Resources\ApiResponse;
use App\Shared\Support\Http\Resources\DataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * @group Offers
 */
class OfferController extends Controller
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OfferEngine $engine,
    ) {}

    public function index(IndexOfferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $offers = Offer::query()
            ->when($validated['active_only'] ?? null, fn ($q) => $q->where('is_active', true))
            ->when($validated['code'] ?? null, fn ($q, $code) => $q->where('code', strtoupper((string) $code)))
            ->latest()
            ->get();

        return ApiResponse::success(OfferResource::collection($offers));
    }

    public function store(StoreOfferRequest $request): JsonResponse
    {
        try {
            $offer = $this->offers->create([
                ...$request->validated(),
                'created_by' => $request->user()?->id,
            ]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'OFFER_VALIDATION_FAILED', 422);
        }

        return ApiResponse::created(new OfferResource($offer), 'Offer created.');
    }

    public function show(Offer $offer): JsonResponse
    {
        return ApiResponse::success(new OfferResource($offer));
    }

    public function update(UpdateOfferRequest $request, Offer $offer): JsonResponse
    {
        try {
            $offer = $this->offers->update($offer, $request->validated());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'OFFER_VALIDATION_FAILED', 422);
        }

        return ApiResponse::success(new OfferResource($offer), 'Offer updated.');
    }

    public function destroy(Offer $offer): Response
    {
        $offer->delete();

        return ApiResponse::noContent();
    }

    public function toggle(Offer $offer): JsonResponse
    {
        try {
            $offer = $this->offers->update($offer, ['is_active' => ! $offer->is_active]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'OFFER_VALIDATION_FAILED', 422);
        }

        $state = $offer->is_active ? 'active' : 'inactive';

        return ApiResponse::success(new OfferResource($offer), "Offer marked as {$state}.");
    }

    public function preview(PreviewOfferRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $lines = collect($validated['items'])->map(function (array $item): OrderItem {
            $qty = (int) $item['quantity'];
            $price = (float) $item['unit_price'];

            $line = new OrderItem([
                'menu_item_id' => $item['menu_item_id'] ?? null,
                'package_id' => $item['package_id'] ?? null,
                'line_type' => empty($item['package_id']) ? OrderItem::LINE_ITEM : OrderItem::LINE_PACKAGE,
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => round($price * $qty, 2),
            ]);

            if (! empty($item['category_id']) && empty($item['package_id'])) {
                $menuItem = new MenuItem(['category_id' => $item['category_id']]);
                $line->setRelation('menuItem', $menuItem);
            }

            return $line;
        });

        try {
            $preview = $this->engine->preview([
                'channel' => $validated['channel'],
                'fulfillment_type' => $validated['fulfillment_type'] ?? null,
                'coupon_code' => isset($validated['coupon_code']) ? strtoupper((string) $validated['coupon_code']) : null,
                'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
                'items' => $lines,
            ]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'OFFER_PREVIEW_FAILED', 422);
        }

        return ApiResponse::success(new DataResource($preview));
    }
}
