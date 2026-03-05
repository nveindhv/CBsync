<?php
namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsUpdatePrice extends Command
{
    protected $signature = 'kms:update:price
        {articleNumber : KMS articleNumber / ERP productCode (15 digits)}
        {price : New price (e.g. 19.95)}
        {--ean= : Optional EAN}
        {--debug : Print debug output}';

    protected $description = 'Update price in KMS using createUpdate and verify before/after.';

    public function handle(KmsClient $kms): int
    {
        $correlationId = (string) Str::uuid();

        $article = (string) $this->argument('articleNumber');
        $price = (float) $this->argument('price');
        $ean = (string) $this->option('ean');
        $debug = (bool) $this->option('debug');

        $this->line("[START] article={$article} new_price={$price}");

        $before = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ], $correlationId);

        $this->line("[BEFORE_COUNT] ".(is_array($before) ? count($before) : 0));

        if ($debug) {
            $this->line(json_encode($before, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }

        $payload = [
            'products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'price' => $price,
                'ean' => $ean
            ]]
        ];

        if ($debug) {
            $this->line("[PAYLOAD] ".json_encode($payload));
        }

        $resp = $kms->post('kms/product/createUpdate', $payload, $correlationId);

        $this->line("[CREATEUPDATE_RESPONSE] ".json_encode($resp));

        $after = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ], $correlationId);

        $this->line("[AFTER_COUNT] ".(is_array($after) ? count($after) : 0));

        if ($debug) {
            $this->line(json_encode($after, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
