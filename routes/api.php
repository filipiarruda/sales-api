<?php

use App\Http\Controllers\CustomerPointsController;
use App\Http\Controllers\SalesCsvImportController;
use App\Http\Controllers\WebhookSaleController;
use Illuminate\Support\Facades\Route;

Route::middleware('webhook.signature')->group(function (): void {
    Route::post('/webhooks/sales', [WebhookSaleController::class, 'store']);
    Route::post('/imports/sales', [SalesCsvImportController::class, 'store']);
});

Route::middleware('auth:sanctum')->get('/customers/{customer}/points', [CustomerPointsController::class, 'show']);
