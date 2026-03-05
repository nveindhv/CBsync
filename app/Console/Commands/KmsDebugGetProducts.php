<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;

class KmsDebugGetProducts extends Command
{
    protected $signature = 'kms:debug:get-products
        {--article-number= : Filter by KMS articleNumber}
        {--ean= : Filter by ean}
        {--offset=0 : Offset}
        {--limit=10 : Limit}
        {--debug : Dump request + raw response}';

    protected $description = 'Debug KMS getProducts with explicit filters (articleNumber / ean) and show exact request/response.';

    public function handle(KmsClient $kms): int
    {
        $articleNumber = $this->option('article-number');
        $ean = $this->option('ean');
        $offset = (int)$this->option('offset');
        $limit = (int)$this->option('limit');
        $debug = (bool)$this->option('debug');

        $payload = [
            'offset' => $offset,
            'limit' => $limit,
        ];
        if ($articleNumber !== null && $articleNumber !== '') {
            $payload['articleNumber'] = (string)$articleNumber;
        }
        if ($ean !== null && $ean !== '') {
            $payload['ean'] = (string)$ean;
        }

        if ($debug) {
            $this->line('POST kms/product/getProducts');
            $this->line('PAYLOAD='.json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw = $kms->post('kms/product/getProducts', $payload);

        // KMS may return keyed object. Normalize to list for counts.
        $list = is_array($raw) ? array_values($raw) : [];

        $this->info('COUNT='.count($list));
        $this->line(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
