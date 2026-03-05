<?php

namespace App\Console\Commands;

use App\Services\Erp\ErpClient;
use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;

class CatalogKmsSeedFromFamilyDump extends Command
{
    protected $signature = 'catalog:kms:seed-from-dump
        {dump_json : Path to family_dump_*.json (storage/app/...)}
        {--target=5 : Stop after this many successful creations}
        {--price=12.34 : Price to set for created products (demo)}
        {--purchase-price= : If empty, use ERP costPrice}
        {--supplier-name= : Force supplier_name (default: ERP searchName)}
        {--type-number-length=11 : type_number = first N chars of ERP productCode}
        {--dry-run : Do not call createUpdate}
        {--debug : Verbose}';

    protected $description = 'Seed missing KMS products from an existing family_dump JSON (no big ERP scans). Fetch each ERP product by filter productCode EQ, then createUpdate in KMS and verify.';

    public function handle(ErpClient $erp, KmsClient $kms): int
    {
        $path = (string)$this->argument('dump_json');
        $target = (int)$this->option('target');
        $price = (float)$this->option('price');
        $purchasePriceOpt = $this->option('purchase-price');
        $supplierForced = (string)($this->option('supplier-name') ?? '');
        $typeLen = (int)$this->option('type-number-length');
        $dryRun = (bool)$this->option('dry-run');
        $debug = (bool)$this->option('debug');

        if (!is_file($path)) {
            $this->error('File not found: '.$path);
            return self::FAILURE;
        }

        $json = json_decode(file_get_contents($path) ?: '[]', true);
        if (!is_array($json) || !isset($json['variants']) || !is_array($json['variants'])) {
            $this->error('Invalid dump JSON (expected {variants:[...]})');
            return self::FAILURE;
        }

        $created = 0;
        $attempted = 0;

        foreach ($json['variants'] as $row) {
            $variant = (string)($row['variant'] ?? '');
            if ($variant === '') continue;

            // Only process missing ones
            if (isset($row['kms_exists']) && $row['kms_exists'] === true) {
                continue;
            }

            $attempted++;
            $this->info("[TRY] {$variant}");

            // Fetch ERP product by filter (no scanning)
            $erpProduct = $this->erpGetProductByCode($erp, $variant);
            if ($erpProduct === null) {
                $this->warn('  ERP: not found');
                continue;
            }

            $ean = (string)($erpProduct['eanCodeAsText'] ?? '');
            if ($ean === '') {
                // fallback to numeric eanCode
                $ean = (string)($erpProduct['eanCode'] ?? '');
            }

            $name = trim((string)($erpProduct['description'] ?? ''));
            $brand = trim((string)($erpProduct['searchName'] ?? ''));
            $unit = trim((string)($erpProduct['unitCode'] ?? ''));

            $purchasePrice = $purchasePriceOpt !== null && $purchasePriceOpt !== ''
                ? (float)$purchasePriceOpt
                : (float)($erpProduct['costPrice'] ?? 0);

            $supplierName = $supplierForced !== '' ? $supplierForced : $brand;

            $typeNumber = substr($variant, 0, $typeLen);
            $typeName = 'FAMILY '.$typeNumber;

            $payload = [
                'products' => [[
                    'article_number' => $variant,
                    'ean' => $ean,
                    'name' => $name !== '' ? $name : ('TEST '.$variant),
                    'description' => 'Seeded from ERP',
                    'price' => $price,
                    'purchase_price' => $purchasePrice,
                    'supplier_name' => $supplierName,
                    'brand' => $brand,
                    'unit' => $unit,
                    'type_number' => $typeNumber,
                    'type_name' => $typeName,
                ]],
            ];

            if ($debug) {
                $this->line('  PAYLOAD='.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if (!$dryRun) {
                $resp = $kms->post('kms/product/createUpdate', $payload);
                $this->line('  CREATEUPDATE='.json_encode($resp, JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('  CREATEUPDATE=DRY-RUN');
            }

            // verify
            $after = $this->kmsGetByArticle($kms, $variant);
            $found = count($after) > 0;
            $this->line('  AFTER_COUNT='.count($after));

            if ($found) {
                $created++;
                $this->info('  CREATED ✔');
            } else {
                $this->warn('  NOT_CREATED ✖');
            }

            if ($created >= $target) {
                $this->info("[STOP] target created={$target} reached");
                break;
            }
        }

        $this->info("DONE attempted={$attempted} created={$created}");
        return self::SUCCESS;
    }

    private function erpGetProductByCode(ErpClient $erp, string $productCode): ?array
    {
        // Use the same trick as the documented Windows one-liner: filter=productCode EQ '<code>'
        $params = [
            'filter' => "productCode EQ '{$productCode}'",
        ];
        $res = $erp->get('products', 0, 1, $params);
        if (!is_array($res) || count($res) === 0) {
            return null;
        }
        return $res[0];
    }

    private function kmsGetByArticle(KmsClient $kms, string $article): array
    {
        $raw = $kms->post('kms/product/getProducts', ['offset' => 0, 'limit' => 50, 'articleNumber' => $article]);
        return is_array($raw) ? array_values($raw) : [];
    }
}
