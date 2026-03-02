<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Services\KMS\KMSClient;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Demo route:
 * - Updates price of a KNOWN variant article_number (must exist in KMS UI list).
 * - This proves KMS write works and your auth/token works.
 *
 * Usage:
 *   /demo/kms/product?key=quest
 * Optional:
 *   /demo/kms/product?key=quest&article_number=505050065001440&price=199.99&purchase_price=80
 */
Route::get('/demo/kms/product', function (KMSClient $kms) {
    $expectedKey = (string) env('DEMO_ROUTE_KEY', '');
    $givenKey = (string) request()->query('key', '');

    if ($expectedKey !== '' && $givenKey !== $expectedKey) {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    $correlationId = (string) Str::uuid();

    try {
        // Default values: use the proven working variant from your screenshot/Postman
        $articleNumber = (string) request()->query('article_number', '505050065001440');
        $price = (float) request()->query('price', 199.99);
        $purchasePrice = (float) request()->query('purchase_price', 80);

        // BEFORE
        $before = $kms->getProductByArticleNumber($articleNumber, $correlationId);

        $product = [
            'article_number' => $articleNumber,
            'ean' => $before['ean'] ?? '',
            'price' => $price,
            'purchase_price' => $purchasePrice,
        ];


        $upsertResponse = $kms->createUpdateProducts([$product], $correlationId);

        // AFTER (small wait because KMS may apply async-ish)
        usleep(400 * 1000);
        $after = $kms->getProductByArticleNumber($articleNumber, $correlationId);

        return response()->json([
            'correlation_id' => $correlationId,
            'article_number' => $articleNumber,
            'payload_sent' => $product,
            'upsert_response' => $upsertResponse,
            'before' => $before,
            'after' => $after,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'correlation_id' => $correlationId,
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ], 500);
    }
});
