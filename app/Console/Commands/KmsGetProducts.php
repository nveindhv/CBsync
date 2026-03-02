<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetProducts extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:products
        {--limit=50 : Page size}
        {--offset=0 : Start offset}
        {--max-pages=1 : Max number of pages}
        {--modified-since= : Optional ISO date (YYYY-MM-DD) filter, if supported by your KMS}
    ';

    protected $description = 'KMS: Haal producten op (kms/product/getProducts)';

    public function handle(KmsClient $client): int
    {
        $payload = [];

        if ($this->option('modified-since')) {
            // Many KMS installs accept this filter; if yours does not, remove.
            $payload['modifiedSince'] = $this->option('modified-since');
        }

        // NOTE: According to the provided reference, this endpoint is NOT under /kms but /product.
        return $this->callPaged($client, 'kms/product/getProducts', $payload, 'products');
    }
}
