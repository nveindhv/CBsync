<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;

class KmsCreateUpdateProbe extends Command
{
    protected $signature = 'kms:probe:create-update
        {article : article_number to try}
        {--ean= : ean to send (required for some KMS configs)}
        {--price=12.34 : price}
        {--purchase-price=0 : purchase_price}
        {--name= : product name (default: TEST <article>)}
        {--description=Demo createUpdate probe : description}
        {--supplier-name= : supplier_name (IMPORTANT for some KMS installs)}
        {--brand= : brand}
        {--unit= : unit}
        {--color= : color}
        {--size= : size}
        {--type-number= : type_number / family number}
        {--type-name= : type_name / family name}
        {--mode=matrix : matrix|single (matrix tries multiple payload variants)}
        {--dry-run : do not call createUpdate}
        {--debug : verbose}';

    protected $description = 'Probe KMS product/createUpdate: try multiple payload variants and verify via getProducts(articleNumber) + getProducts(ean).';

    public function handle(KmsClient $kms): int
    {
        $article = (string)$this->argument('article');
        $ean = (string)($this->option('ean') ?? '');
        $price = (float)$this->option('price');
        $purchasePrice = (float)$this->option('purchase-price');
        $name = (string)($this->option('name') ?? '');
        $description = (string)($this->option('description') ?? '');
        $supplierName = (string)($this->option('supplier-name') ?? '');
        $brand = (string)($this->option('brand') ?? '');
        $unit = (string)($this->option('unit') ?? '');
        $color = (string)($this->option('color') ?? '');
        $size = (string)($this->option('size') ?? '');
        $typeNumber = (string)($this->option('type-number') ?? '');
        $typeName = (string)($this->option('type-name') ?? '');
        $mode = (string)($this->option('mode') ?? 'matrix');
        $dryRun = (bool)$this->option('dry-run');
        $debug = (bool)$this->option('debug');

        if ($name === '') {
            $name = 'TEST '.$article;
        }

        $this->info('=== KMS CREATEUPDATE PROBE ===');
        $this->line("article={$article} ean=".($ean !== '' ? $ean : '[none]')." mode={$mode} dry_run=".($dryRun?'true':'false'));

        // Baseline verify BEFORE
        $beforeA = $this->getByArticle($kms, $article);
        $beforeE = $ean !== '' ? $this->getByEan($kms, $ean) : [];
        $this->line('BEFORE articleCount='.count($beforeA).' eanCount='.count($beforeE));

        $variants = [];
        if ($mode === 'single') {
            $variants['SINGLE'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, $supplierName, $brand, $unit, $color, $size, $typeNumber, $typeName);
        } else {
            // MATRIX: progressively add fields commonly required in KMS implementations.
            $variants['MINIMAL_DOC'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, '', '', '', '', '', '', '');
            $variants['+SUPPLIER'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, $supplierName, '', '', '', '', '', '');
            $variants['+BRAND_UNIT'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, $supplierName, $brand, $unit, '', '', '', '');
            $variants['+COLOR_SIZE'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, $supplierName, $brand, $unit, $color, $size, '', '');
            $variants['+TYPE'] = $this->buildPayload($article, $ean, $name, $description, $price, $purchasePrice, $supplierName, $brand, $unit, $color, $size, $typeNumber, $typeName);

            // Also try the camelCase keys some code paths accidentally use.
            $variants['CAMELCASE_KEYS'] = [
                'products' => [[
                    'articleNumber' => $article,
                    'article_number' => $article,
                    'ean' => $ean,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'purchase_price' => $purchasePrice,
                    'supplierName' => $supplierName,
                    'supplier_name' => $supplierName,
                    'brand' => $brand,
                    'unit' => $unit,
                    'color' => $color,
                    'size' => $size,
                    'type_number' => $typeNumber,
                    'type_name' => $typeName,
                ]],
            ];
        }

        foreach ($variants as $label => $body) {
            $this->newLine();
            $this->info("--- VARIANT: {$label} ---");
            if ($debug) {
                $this->line('PAYLOAD='.json_encode($body, JSON_UNESCAPED_SLASHES));
            }

            if (!$dryRun) {
                $resp = $kms->post('kms/product/createUpdate', $body);
                $this->line('CREATEUPDATE='.json_encode($resp, JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('CREATEUPDATE=DRY-RUN');
            }

            // Verify AFTER
            $afterA = $this->getByArticle($kms, $article);
            $afterE = $ean !== '' ? $this->getByEan($kms, $ean) : [];

            $created = (count($afterA) > 0) || (count($afterE) > 0);
            $this->line('AFTER articleCount='.count($afterA).' eanCount='.count($afterE));
            $this->line('RESULT='.($created ? 'CREATED_OR_FOUND' : 'NOT_FOUND'));

            if ($created) {
                // Print a compact snapshot of what KMS stored.
                $snapshot = count($afterA) > 0 ? $afterA[0] : $afterE[0];
                $this->line('SNAPSHOT='.json_encode($this->pickFields($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                break; // stop at first working variant
            }
        }

        return self::SUCCESS;
    }

    private function buildPayload(
        string $article,
        string $ean,
        string $name,
        string $description,
        float $price,
        float $purchasePrice,
        string $supplierName,
        string $brand,
        string $unit,
        string $color,
        string $size,
        string $typeNumber,
        string $typeName
    ): array {
        $p = [
            'article_number' => $article,
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'purchase_price' => $purchasePrice,
        ];
        if ($ean !== '') $p['ean'] = $ean;
        if ($supplierName !== '') $p['supplier_name'] = $supplierName;
        if ($brand !== '') $p['brand'] = $brand;
        if ($unit !== '') $p['unit'] = $unit;
        if ($color !== '') $p['color'] = $color;
        if ($size !== '') $p['size'] = $size;
        if ($typeNumber !== '') $p['type_number'] = $typeNumber;
        if ($typeName !== '') $p['type_name'] = $typeName;

        return ['products' => [$p]];
    }

    private function getByArticle(KmsClient $kms, string $article): array
    {
        $raw = $kms->post('kms/product/getProducts', ['offset' => 0, 'limit' => 50, 'articleNumber' => $article]);
        return is_array($raw) ? array_values($raw) : [];
    }

    private function getByEan(KmsClient $kms, string $ean): array
    {
        $raw = $kms->post('kms/product/getProducts', ['offset' => 0, 'limit' => 50, 'ean' => $ean]);
        return is_array($raw) ? array_values($raw) : [];
    }

    private function pickFields(array $p): array
    {
        $keys = ['id','articleNumber','ean','name','price','purchasePrice','unit','brand','color','size','supplierName','modifyDate'];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $p)) $out[$k] = $p[$k];
        }
        return $out;
    }
}
