<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KmsController;

Route::prefix('kms')->group(function () {

    // Products
    Route::post('/products/get', [KmsController::class, 'getProducts']);
    Route::post('/products/upsert', [KmsController::class, 'upsertProducts']);

    // Orders / Finance
    Route::post('/orders/get', [KmsController::class, 'getOrders']);
    Route::post('/orders/create', [KmsController::class, 'createOrder']);
    Route::post('/finance/update', [KmsController::class, 'updateFinance']);

    // Customers
    Route::post('/business/upsert', [KmsController::class, 'upsertBusiness']);
    Route::post('/business/price-agreements', [KmsController::class, 'updatePriceAgreements']);
});
