<?php

namespace App\Console\Commands;

/**
 * Update stock (technical stock) in KMS.
 *
 * Endpoint:
 *   POST kms/stock/updateStock
 */
class KmsPostStockUpdateStock extends KmsBasePostCommand
{
    protected $signature = 'kms:post:stock-update
        {--file= : Path to JSON payload file (overrides other options)}
        {--ean= : EAN/SKU}
        {--stock= : Technical stock}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: update stock for one or more products (kms/stock/updateStock)';

    protected function endpoint(): string
    {
        return 'kms/stock/updateStock';
    }

    protected function buildPayload(): array
    {
        $product = array_filter([
            'ean'            => $this->option('ean'),
            'technicalStock' => $this->option('stock') !== null ? (int) $this->option('stock') : null,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'products' => [$product],
        ];
    }
}
