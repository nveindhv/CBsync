<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetBusinesses extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:businesses
        {--limit=50}
        {--offset=0}
        {--max-pages=1}
        {--search= : Optional search term}
    ';

    protected $description = 'KMS: Haal klanten/bedrijven op (kms/business/list)';

    public function handle(KmsClient $client): int
    {
        $payload = [];
        if ($this->option('search')) {
            $payload['search'] = $this->option('search');
        }

        return $this->callPaged($client, 'kms/business/list', $payload, 'businesses');
    }
}
