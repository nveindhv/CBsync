<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class KmsReverseCreatePath extends Command
{
    protected $signature = 'kms:reverse:create-path
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
        {--cleanup : Try to delete/hide the created product again after a successful visibility test}
        {--debug : Dump payloads and raw responses}';

    protected $description = 'Probe which createUpdate payload shape can CREATE / make visible a missing KMS article.';

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
        $debug = (bool) $this->option('debug');
        $cleanup = (bool) $this->option('cleanup');
        $familyLen = max(1, (int) ($this->option('family-len') ?? 9));

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

        $this->line('=== KMS CREATE PATH PROBE (v1.1) ===');
        $this->line('Article     : ' . $article);
        if ($ean !== null) {
            $this->line('EAN         : ' . $ean);
        }
        $this->line('type_number : ' . ($typeNumber ?? '[none]'));
        $this->line('type_name   : ' . ($typeName ?? '[none]'));
        $this->line('Cleanup     : ' . ($cleanup ? 'YES' : 'NO'));
        $this->newLine();

        $existing = $this->fetchOne($kms, $article, $ean, $debug);
        if ($existing) {
            $this->warn('Article is already visible in KMS. This command is meant for missing/create-path probing.');
            $this->line('Snapshot: ' . json_encode($existing, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $recipes = [];

        $recipes['minimal_price_only'] = array_filter([
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => $ean,
            'price' => $price,
        ], fn ($v) => $v !== null && $v !== '');

        $recipes['context_no_type'] = $this->basePayload(
            $article,
            $ean,
            $unit,
            null,
            null,
            null,
            null,
            $price,
            $purchasePrice,
            null,
            $brand,
            $color,
            $size,
        );

        $recipes['context_with_name_no_type'] = $this->basePayload(
            $article,
            $ean,
            $unit,
            $name,
            null,
            null,
            null,
            $price,
            $purchasePrice,
            null,
            $brand,
            $color,
            $size,
        );

        $recipes['context_with_type'] = $this->basePayload(
            $article,
            $ean,
            $unit,
            null,
            $typeNumber,
            $typeName,
            null,
            $price,
            $purchasePrice,
            null,
            $brand,
            $color,
            $size,
        );

        $recipes['context_with_type_and_name'] = $this->basePayload(
            $article,
            $ean,
            $unit,
            $name,
            $typeNumber,
            $typeName,
            null,
            $price,
            $purchasePrice,
            null,
            $brand,
            $color,
            $size,
        );

        $recipes['rich_with_type_name_context'] = $this->basePayload(
            $article,
            $ean,
            $unit,
            $name,
            $typeNumber,
            $typeName,
            $supplierName,
            $price,
            $purchasePrice,
            null,
            $brand,
            $color,
            $size,
        );

        foreach ($recipes as $recipe => $productPayload) {
            $this->line('--- Recipe: ' . $recipe . ' ---');
            $payload = ['products' => [$productPayload]];

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
            $visible = $this->fetchOne($kms, $article, $ean, $debug);
            if ($visible) {
                $this->info('VISIBLE ✔ via recipe=' . $recipe);
                $this->line('Snapshot: ' . json_encode($visible, JSON_UNESCAPED_SLASHES));

                if ($cleanup) {
                    $cleanupPayload = [
                        'products' => [[
                            'article_number' => $article,
                            'articleNumber' => $article,
                            'ean' => $ean,
                            'deleted' => true,
                        ]],
                    ];
                    $kms->post('kms/product/createUpdate', $cleanupPayload);
                    $this->warn('Cleanup payload sent (deleted=true). Verify manually.');
                }

                $this->newLine();
                $this->comment('Best current interpretation:');
                $this->line('- Missing KMS variants often need a rich payload.');
                $this->line('- Family/type should follow the product code structure rather than a blind 11-char rule.');
                $this->line('- For these ERP articles the safest current default is family-len=' . $familyLen . ' unless you explicitly force type_number.');
                return self::SUCCESS;
            }

            $this->warn('Still not visible after recipe=' . $recipe);
            $this->newLine();
        }

        $this->error('No tested recipe made the article visible.');
        $this->comment('Next step: verify the correct family/type grouping and/or enrich payload with additional ERP-derived context.');
        return self::FAILURE;
    }

    private function basePayload(
        string $article,
        ?string $ean,
        ?string $unit,
        ?string $name,
        ?string $typeNumber,
        ?string $typeName,
        ?string $supplierName,
        ?float $price,
        ?float $purchasePrice,
        ?int $amount,
        ?string $brand,
        ?string $color,
        ?string $size,
    ): array {
        $p = [
            'article_number' => $article,
            'articleNumber' => $article,
        ];

        if ($ean !== null && $ean !== '') {
            $p['ean'] = $ean;
        }
        if ($unit !== null && $unit !== '') {
            $p['unit'] = $unit;
        }
        if ($name !== null && $name !== '') {
            $p['name'] = $name;
        }
        if ($typeNumber !== null && $typeNumber !== '') {
            $p['type_number'] = $typeNumber;
            $p['typeNumber'] = $typeNumber;
        }
        if ($typeName !== null && $typeName !== '') {
            $p['type_name'] = $typeName;
            $p['typeName'] = $typeName;
        }
        if ($brand !== null && $brand !== '') {
            $p['brand'] = $brand;
        }
        if ($color !== null && $color !== '') {
            $p['color'] = $color;
        }
        if ($size !== null && $size !== '') {
            $p['size'] = $size;
        }
        if ($price !== null) {
            $p['price'] = $price;
        }
        if ($purchasePrice !== null) {
            $p['purchase_price'] = $purchasePrice;
        }
        if ($supplierName !== null && $supplierName !== '') {
            $p['supplier_name'] = $supplierName;
        }
        if ($amount !== null) {
            $p['amount'] = $amount;
        }

        return $p;
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
