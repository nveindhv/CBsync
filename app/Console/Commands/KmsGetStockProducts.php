<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetStockProducts extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:stock-products
	    {--type= : Optional scope type (e.g. business)}
	    {--id= : Optional scope id (e.g. business id)}
        {--limit=50}
        {--offset=0}
        {--max-pages=1}
        {--warehouse= : Optional warehouse code}
    ';

    protected $description = 'KMS: Haal voorraadproducten op (kms/stock/getProducts)';

    public function handle(KmsClient $client): int
    {
        $payload = [];
	    if ($this->option('type')) {
	        $payload['type'] = (string) $this->option('type');
	    }
	    if ($this->option('id')) {
	        $payload['id'] = (string) $this->option('id');
	    }
        if ($this->option('warehouse')) {
            $payload['warehouse'] = $this->option('warehouse');
        }

        return $this->callPaged($client, 'kms/stock/getProducts', $payload, 'stock/products');
    }
}
