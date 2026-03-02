<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetFinances extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:finances
        {--type= : Required. Finance type (order, invoice, credit, offer, backorder)}
        {--limit=50}
        {--offset=0}
        {--max-pages=1}
        {--from= : Optional start date YYYY-MM-DD}
        {--to= : Optional end date YYYY-MM-DD}
    ';

    protected $description = 'KMS: Haal finance records op (kms/finance/getFinances)';

    public function handle(KmsClient $client): int
    {
        $type = (string) ($this->option('type') ?? '');
        if ($type === '') {
            $this->error('Missing required option: --type (invoice, credit, order, offer, backorder, ...)');
            return self::FAILURE;
        }

        $payload = ['type' => $type];
        if ($this->option('from')) $payload['dateFrom'] = $this->option('from');
        if ($this->option('to')) $payload['dateTo'] = $this->option('to');

        return $this->callPaged($client, 'kms/finance/getFinances', $payload, 'finances');
    }
}
