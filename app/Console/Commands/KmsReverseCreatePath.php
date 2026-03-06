<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class KmsReverseCreatePath extends Command
{
    protected $signature = 'kms:reverse:create-path
        {article : Full KMS articleNumber}
        {--ean= : EAN}
        {--unit= : Unit}
        {--brand= : Brand}
        {--color= : Color}
        {--size= : Size}
        {--price= : Sales price}
        {--purchase-price= : Purchase price (snake_case works better)}
        {--supplier-name= : Supplier name (snake_case works better)}
        {--name= : Product name}
        {--type-number= : Force type_number; default first 11 chars of article}
        {--type-name= : Force type_name; default FAMILY <type_number>}
        {--family-len=11 : Prefix length for derived type_number}
        {--sleep=250 : Milliseconds between requests}
        {--cleanup : Try to clean up afterwards by setting deleted=true}
        {--debug : Dump payloads and raw responses}';

    protected $description = 'Probe which createUpdate payload shape can CREATE / make visible a missing KMS article.';

    public function handle(): int
    {
        $article = (string) $this->argument('article');
        $ean = $this->opt('ean');
        $unit = $this->opt('unit');
        $brand = $this->opt('brand');
        $color = $this->opt('color');
        $size = $this->opt('size');
        $name = $this->opt('name');
        $supplierName = $this->opt('supplier-name');
        $price = $this->numOpt('price');
        $purchasePrice = $this->numOpt('purchase-price');
        $sleepMs = (int) ($this->option('sleep') ?? 250);
        $debug = (bool) $this->option('debug');
        $cleanup = (bool) $this->option('cleanup');

        $familyLen = max(1, (int) ($this->option('family-len') ?? 11));
        $typeNumber = $this->opt('type-number') ?: (strlen($article) >= $familyLen ? substr($article, 0, $familyLen) : $article);
        $typeName = $this->opt('type-name') ?: ('FAMILY ' . $typeNumber);

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $this->line('=== KMS CREATE PATH PROBE (v1.0) ===');
        $this->line('Article     : ' . $article);
        if ($ean !== null) {
            $this->line('EAN         : ' . $ean);
        }
        $this->line('type_number : ' . $typeNumber);
        $this->line('type_name   : ' . $typeName);
        $this->line('Cleanup     : ' . ($cleanup ? 'YES' : 'NO'));
        $this->newLine();

        $existing = $this->fetchOne($kms, $article, $ean, $debug);
        if ($existing) {
            $this->warn('Article is already visible in KMS. This command is meant for missing/create-path probing.');
            $this->line('Snapshot: ' . json_encode($existing, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $recipes = [
            'minimal_price_only' => [
                'price' => $price,
            ],
            'context_no_type' => [
                'unit' => $unit,
                'brand' => $brand,
                'color' => $color,
                'size' => $size,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'supplier_name' => $supplierName,
            ],
            'context_with_name_no_type' => [
                'unit' => $unit,
                'brand' => $brand,
                'color' => $color,
                'size' => $size,
                'name' => $name,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'supplier_name' => $supplierName,
            ],
            'context_with_type' => [
                'unit' => $unit,
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
            ],
            'context_with_type_and_name' => [
                'unit' => $unit,
                'brand' => $brand,
                'color' => $color,
                'size' => $size,
                'name' => $name,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'supplier_name' => $supplierName,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'type_name' => $typeName,
                'typeName' => $typeName,
            ],
        ];

        foreach ($recipes as $recipeName => $fields) {
            $payload = $this->payload($article, $ean, $fields);

            $this->line('--- Recipe: ' . $recipeName . ' ---');
            if ($debug) {
                $this->line('PAYLOAD:');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $raw = $kms->post('kms/product/createUpdate', $payload);
            if ($debug) {
                $this->line('RAW RESPONSE:');
                $this->line(json_encode($raw, JSON_UNESCAPED_SLASHES));
            }

            usleep($sleepMs * 1000);
            $snapshot = $this->fetchOne($kms, $article, $ean, $debug);

            if ($snapshot) {
                $this->info('VISIBLE ✔ via recipe=' . $recipeName);
                $this->line('Snapshot: ' . json_encode($snapshot, JSON_UNESCAPED_SLASHES));
                $this->newLine();
                $this->comment('Best current interpretation:');
                $this->line('- UPDATE path for existing KMS variants is largely understood.');
                $this->line('- CREATE path still depends on article/family semantics and accepted payload shape.');
                $this->line('- The winning recipe above is the most useful next building block for ERP -> KMS sync.');

                if ($cleanup) {
                    $this->newLine();
                    $this->warn('Cleanup requested: attempting soft hide via deleted=true');
                    $cleanupPayload = $this->payload($article, $ean, [
                        'unit' => $unit,
                        'brand' => $brand,
                        'color' => $color,
                        'size' => $size,
                        'deleted' => true,
                    ]);
                    if ($debug) {
                        $this->line('CLEANUP PAYLOAD:');
                        $this->line(json_encode($cleanupPayload, JSON_UNESCAPED_SLASHES));
                    }
                    $kms->post('kms/product/createUpdate', $cleanupPayload);
                }

                return self::SUCCESS;
            }

            $this->warn('Still not visible after recipe=' . $recipeName);
            $this->newLine();
        }

        $this->error('No tested recipe made the article visible.');
        return self::FAILURE;
    }

    private function payload(string $article, ?string $ean, array $fields): array
    {
        $product = [
            'article_number' => $article,
            'articleNumber' => $article,
        ];

        if ($ean !== null && $ean !== '') {
            $product['ean'] = $ean;
        }

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $product[$key] = $value;
        }

        return ['products' => [$product]];
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ]);

        $items = $this->normalize($res);
        if ($debug) {
            $this->line('fetchOne article lookup count=' . count($items));
            $this->line('fetchOne article raw=' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }

        foreach ($items as $item) {
            if ((string) Arr::get($item, 'articleNumber') === $article) {
                return $item;
            }
        }

        if ($ean !== null && $ean !== '') {
            $res2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ]);
            $items2 = $this->normalize($res2);
            if ($debug) {
                $this->line('fetchOne ean lookup count=' . count($items2));
                $this->line('fetchOne ean raw=' . json_encode($res2, JSON_UNESCAPED_SLASHES));
            }
            foreach ($items2 as $item) {
                if ((string) Arr::get($item, 'articleNumber') === $article || (string) Arr::get($item, 'ean') === $ean) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function normalize($res): array
    {
        if (!is_array($res) || $res === []) {
            return [];
        }

        $keys = array_keys($res);
        $isList = $keys === range(0, count($keys) - 1);
        $items = $isList ? $res : array_values($res);

        return array_values(array_filter($items, static fn ($row) => is_array($row)));
    }

    private function opt(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function numOpt(string $name): float|int|null
    {
        $value = $this->opt($name);
        if ($value === null) {
            return null;
        }
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
