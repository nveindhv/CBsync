<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetStockMutations extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:stock-mutations
	    {--type= : Optional scope type (e.g. business)}
	    {--id= : Optional scope id (e.g. business id)}
        {--limit=50}
        {--offset=0}
        {--max-pages=1}
        {--from= : Optional start date/datetime (YYYY-MM-DD or ISO). Mapped to KMS `dateTime`}
        {--to= : Optional end date/datetime (YYYY-MM-DD or ISO). Not supported by KMS; ignored}
        {--ean= : Optional EAN to filter}
        {--article-number= : Optional article number to filter}
    ';

    protected $description = 'KMS: Haal voorraadmutaties op (kms/stock/mutations)';

    public function handle(KmsClient $client): int
    {
        $payload = [];
	    if ($this->option('type')) $payload['type'] = (string) $this->option('type');
	    if ($this->option('id')) $payload['id'] = (string) $this->option('id');

        // KMS expects: dateTime (single value), ean and/or article_number.
        if ($this->option('from')) $payload['dateTime'] = $this->option('from');
        if ($this->option('ean')) $payload['ean'] = (string) $this->option('ean');
        if ($this->option('article-number')) $payload['article_number'] = (string) $this->option('article-number');

        if ($this->option('to')) {
            $this->warn('Note: --to is not supported by KMS for stock mutations; ignoring. Use --from only.');
        }

        return $this->callPaged($client, 'kms/stock/mutations', $payload, 'stock/mutations');
    }
}
