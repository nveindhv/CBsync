<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;

class KmsRepairProductVisibility extends Command
{
    protected $signature = 'kms:repair:product-visibility
        {article : Full articleNumber}
        {--ean= : EAN if known}
        {--unit= : Unit, e.g. STK or PAAR}
        {--brand= : Brand}
        {--color= : Color}
        {--size= : Size}
        {--price= : Price}
        {--purchase-price= : Purchase price}
        {--vat= : VAT percentage}
        {--amount= : Amount}
        {--supplier-name= : Supplier name}
        {--active=1 : Set is_active to 1 or 0}
        {--deleted=0 : Set deleted to 1 or 0}
        {--include-id= : Optional ID if you know it}
        {--dry-run : Only show payload}
        {--debug : Dump payload and raw response}';

    protected $description = 'Attempt to make a KMS product visible again after a destructive reverse test (inactive/deleted/hidden).';

    public function handle(): int
    {
        $article = (string) $this->argument('article');
        $ean = $this->option('ean') ? (string) $this->option('ean') : null;
        $debug = (bool) $this->option('debug');

        $product = [
            'article_number' => $article,
            'articleNumber' => $article,
            'is_active' => $this->toBoolInt($this->option('active')),
            'deleted' => $this->toBoolInt($this->option('deleted')),
        ];

        if ($ean) {
            $product['ean'] = $ean;
        }

        foreach ([
            'unit' => 'unit',
            'brand' => 'brand',
            'color' => 'color',
            'size' => 'size',
            'price' => 'price',
            'purchase-price' => 'purchase_price',
            'vat' => 'vAT',
            'amount' => 'amount',
            'supplier-name' => 'supplierName',
            'include-id' => 'id',
        ] as $option => $target) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                if (in_array($target, ['price', 'purchase_price'], true)) {
                    $product[$target] = (float) $value;
                } elseif (in_array($target, ['vAT', 'amount', 'id'], true)) {
                    $product[$target] = (int) $value;
                } else {
                    $product[$target] = (string) $value;
                }
            }
        }

        $payload = ['products' => [$product]];

        $this->line('=== KMS PRODUCT VISIBILITY REPAIR (v1.0) ===');
        $this->line('Payload:');
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);
        $response = $kms->post('kms/product/createUpdate', $payload);

        if ($debug) {
            $this->line('createUpdate raw response:');
            $this->line(json_encode($response, JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->info('Repair call sent.');
        $this->line('Check daarna direct:');
        $this->line('php artisan kms:debug:get-products --article-number=' . $article . ' --limit=5 --debug');
        if ($ean) {
            $this->line('php artisan kms:debug:get-products --ean=' . $ean . ' --limit=5 --debug');
        }

        return self::SUCCESS;
    }

    private function toBoolInt($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $s = strtolower((string) $value);
        return in_array($s, ['1', 'true', 'yes', 'y'], true);
    }
}
