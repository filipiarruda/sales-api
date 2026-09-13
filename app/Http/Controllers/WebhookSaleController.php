<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebhookSaleRequest;
use App\Services\CreateSaleService;
use Illuminate\Http\JsonResponse;

class WebhookSaleController extends Controller
{
    public function store(StoreWebhookSaleRequest $request, CreateSaleService $createSale): JsonResponse
    {
        $sale = $createSale->create($request->validated(), 'webhook');

        return response()->json([
            'sale_id' => $sale->id,
            'external_id' => $sale->external_id,
            'status' => $sale->wasRecentlyCreated ? 'created' : 'duplicate',
        ], $sale->wasRecentlyCreated ? 201 : 200);
    }
}
