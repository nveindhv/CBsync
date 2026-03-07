<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class KmsProbeUpsertFlow extends Command
{
    protected $signature = 'kms:probe:upsert-flow
        {article : Full ERP/KMS articleNumber (variant)}
        {--ean= : EAN for the variant}
        {--unit= : Unit, e.g. STK/PAAR}
        {--name= : Product name / description}
        {--brand= : Brand, e.g. TRICORP}
        {--color= : Color, e.g. navy}
        {--size= : Size, e.g. 66}
        {--price= : Sales price}
        {--purchase-price= : Purchase price}
        {--supplier-name= : Supplier name}
        {--type-number= : Force type_number/typeNumber}
        {--type-name= : Force type_name/typeName}
        {--family-len=9 : Length used when deriving the family/type from articleNumber}
        {--debug : Dump payloads and raw responses}';

    protected $description = 'Probe practical ERP -> KMS upsert flow: existing => update without type, missing => create with type + name + context.';

    public function handle(): int
    {
        $article = (string) $this->argument('article');
        $ean = $this->optString('ean');
        $unit = $this->optString('unit');
        $name = $this->optString('name');
        $brand = $this->optString('brand');
        $color = $this->optString('color');
        $size = $this->optString('size');
        $supplierName = $this->optString('supplier-name');
        $typeNumber = $this->optString('type-number');
        $typeName = $this->optString('type-name');
        $familyLen = max(1, (int) ($this->option('family-len') ?? 9));
        $debug = (bool) $this->option('debug');

        $price = $this->optNumeric('price');
        $purchasePrice = $this->optNumeric('purchase-price');

        if (!$typeNumber && strlen($article) >= $familyLen) {
            $typeNumber = substr($article, 0, $familyLen);
        }
        if (!$typeName && $typeNumber) {
            $typeName = 'FAMILY ' . $typeNumber;
        }

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $this->line('=== KMS UPSERT FLOW PROBE (v1.1) ===');
        $this->line('Article     : ' . $article);
        if ($ean !== null) {
            $this->line('EAN         : ' . $ean);
        }
        $this->line('type_number : ' . ($typeNumber ?? '[none]'));
        $this->line('type_name   : ' . ($typeName ?? '[none]'));
        $this->newLine();

        $existing = $this->fetchOne($kms, $article, $ean, $debug);

        if ($existing) {
            $this->info('Existing product found in KMS -> UPDATE path');
            $payload = ['products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'ean' => $ean,
                'unit' => $unit,
                'brand' => $brand,
                'color' => $color,
                'size' => $size,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'supplier_name' => $supplierName,
                'name' => $name,
            ]]];

            $payload['products'][0] = array_filter(
                $payload['products'][0],
                fn ($v) => $v !== null && $v !== ''
            );

            $this->line('--- Step: update_without_type ---');
            if ($debug) {
                $this->line('PAYLOAD:');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
            $raw = $kms->post('kms/product/createUpdate', $payload);
            if ($debug) {
                $this->line('RAW RESPONSE:');
                $this->line(json_encode($raw, JSON_UNESCAPED_SLASHES));
            }
            usleep(350000);
            $after = $this->fetchOne($kms, $article, $ean, $debug);
            if ($after) {
                $this->info('VISIBLE ✔ after update_without_type');
                $this->line('Snapshot: ' . json_encode($after, JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $this->error('Article unexpectedly not visible after update_without_type.');
            return self::FAILURE;
        }

        $this->warn('Missing product in KMS -> CREATE path');
        $payload = ['products' => [[
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => $ean,
            'unit' => $unit,
            'name' => $name,
            'brand' => $brand,
            'color' => $color,
            'size' => $size,
            'price' => $price,
            'purchase_price' => $purchasePrice,
            'supplier_name' => $supplierName,
            'type_number' => $typeNumber,
            'typeNumber' => $typeNumber,
            'type_name' => $typeName,
            'typeName' => $typeName,
        ]]];
        $payload['products'][0] = array_filter(
            $payload['products'][0],
            fn ($v) => $v !== null && $v !== ''
        );

        $this->line('--- Step: create_with_type_and_context ---');
        if ($debug) {
            $this->line('PAYLOAD:');
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $raw = $kms->post('kms/product/createUpdate', $payload);
        if ($debug) {
            $this->line('RAW RESPONSE:');
            $this->line(json_encode($raw, JSON_UNESCAPED_SLASHES));
        }
        usleep(350000);
        $after = $this->fetchOne($kms, $article, $ean, $debug);
        if ($after) {
            $this->info('VISIBLE ✔ after create_with_type_and_context');
            $this->line('Snapshot: ' . json_encode($after, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->error('Still not visible after create_with_type_and_context');
        $this->comment('Likely causes: wrong family/type grouping or still-missing create context.');
        return self::FAILURE;
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 50,
            'articleNumber' => $article,
        ]);
        $items = $this->normalizeProductsResponse($res);
        if ($debug) {
            $this->line('fetchOne article lookup count=' . count($items));
            $this->line('fetchOne article raw=' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        foreach ($items as $p) {
            if ((string) Arr::get($p, 'articleNumber') === $article) {
                return $p;
            }
        }

        if ($ean) {
            $res2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 50,
                'ean' => $ean,
            ]);
            $items2 = $this->normalizeProductsResponse($res2);
            if ($debug) {
                $this->line('fetchOne ean lookup count=' . count($items2));
                $this->line('fetchOne ean raw=' . json_encode($res2, JSON_UNESCAPED_SLASHES));
            }
            foreach ($items2 as $p) {
                if ((string) Arr::get($p, 'articleNumber') === $article) {
                    return $p;
                }
                if ((string) Arr::get($p, 'ean') === $ean) {
                    return $p;
                }
            }
        }

        return null;
    }

    private function normalizeProductsResponse($res): array
    {
        if (!is_array($res) || empty($res)) {
            return [];
        }
        $keys = array_keys($res);
        $isNumericList = ($keys === range(0, count($keys) - 1));
        $items = $isNumericList ? $res : array_values($res);
        return array_values(array_filter($items, fn ($x) => is_array($x)));
    }

    private function optString(string $key): ?string
    {
        $v = $this->option($key);
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function optNumeric(string $key): ?float
    {
        $v = $this->option($key);
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }
}
