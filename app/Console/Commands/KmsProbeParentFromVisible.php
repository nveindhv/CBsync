<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class KmsProbeParentFromVisible extends Command
{
    protected $signature = 'kms:probe:parent-from-visible
        {family9 : 9-char family/type number}
        {basis12a : First 12-char basis article}
        {variantA : First full variant article}
        {--ean-a= : Variant A EAN}
        {--basis12b= : Optional second 12-char basis article}
        {--variantB= : Optional second full variant article}
        {--ean-b= : Variant B EAN}
        {--name=TEST PARENT FROM VISIBLE : Base name}
        {--brand=TESTBRAND : Brand}
        {--unit=STK : Unit}
        {--color-a=navy : Color A}
        {--color-b=black : Color B}
        {--size-a=42 : Size A}
        {--size-b=44 : Size B}
        {--price=12.34 : Price}
        {--purchase-price=0 : Purchase price}
        {--live : Actually POST createUpdate}
        {--write-json : Write report JSON}
        {--debug : Show payloads}';

    protected $description = 'Use the now-proven visible-variant field model to test parent9 and basis12 hypotheses with real-looking payloads.';

    public function handle(): int
    {
        if (! class_exists(\App\Services\Kms\KmsClient::class)) {
            $this->error('KmsClient class not found.');
            return self::FAILURE;
        }

        $family9 = trim((string) $this->argument('family9'));
        $basis12a = trim((string) $this->argument('basis12a'));
        $variantA = trim((string) $this->argument('variantA'));
        $basis12b = trim((string) ($this->option('basis12b') ?: ''));
        $variantB = trim((string) ($this->option('variantB') ?: ''));
        $live = (bool) $this->option('live');
        $debug = (bool) $this->option('debug');

        /** @var \App\Services\Kms\KmsClient $kms */
        $kms = app(\App\Services\Kms\KmsClient::class);

        $price = (float) $this->option('price');
        $purchasePrice = (float) $this->option('purchase-price');
        $brand = (string) $this->option('brand');
        $unit = (string) $this->option('unit');
        $name = (string) $this->option('name');

        $scenarios = [
            'parent9_only' => [[
                'article_number' => $family9,
                'articleNumber' => $family9,
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
            ]],
            'basis12_only' => [[
                'article_number' => $basis12a,
                'articleNumber' => $basis12a,
                'name' => $name . ' ' . $this->option('color-a'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-a'),
            ]],
            'basis12_plus_variant' => [[
                'article_number' => $basis12a,
                'articleNumber' => $basis12a,
                'name' => $name . ' ' . $this->option('color-a'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-a'),
            ], [
                'article_number' => $variantA,
                'articleNumber' => $variantA,
                'ean' => (string) $this->option('ean-a'),
                'name' => $name . ' ' . $this->option('color-a') . ' ' . $this->option('size-a'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-a'),
                'size' => (string) $this->option('size-a'),
            ]],
        ];

        if ($basis12b !== '' && $variantB !== '') {
            $scenarios['two_basis12_colors_then_variants'] = [[
                'article_number' => $basis12a,
                'articleNumber' => $basis12a,
                'name' => $name . ' ' . $this->option('color-a'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-a'),
            ], [
                'article_number' => $basis12b,
                'articleNumber' => $basis12b,
                'name' => $name . ' ' . $this->option('color-b'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-b'),
            ], [
                'article_number' => $variantA,
                'articleNumber' => $variantA,
                'ean' => (string) $this->option('ean-a'),
                'name' => $name . ' ' . $this->option('color-a') . ' ' . $this->option('size-a'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-a'),
                'size' => (string) $this->option('size-a'),
            ], [
                'article_number' => $variantB,
                'articleNumber' => $variantB,
                'ean' => (string) $this->option('ean-b'),
                'name' => $name . ' ' . $this->option('color-b') . ' ' . $this->option('size-b'),
                'brand' => $brand,
                'unit' => $unit,
                'price' => $price,
                'purchase_price' => $purchasePrice,
                'type_number' => $family9,
                'type_name' => $name,
                'color' => (string) $this->option('color-b'),
                'size' => (string) $this->option('size-b'),
            ]];
        }

        $report = [];
        $this->line('=== KMS PARENT FROM VISIBLE PROBE ===');
        $this->line('Mode    : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Family9 : ' . $family9);

        foreach ($scenarios as $label => $rows) {
            $payload = ['products' => $rows];
            if ($debug) {
                $this->line('--- ' . $label . ' ---');
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            $before = [];
            foreach ($rows as $row) {
                $before[(string) $row['article_number']] = $this->lookup($kms, (string) $row['article_number'], (string) ($row['ean'] ?? ''));
            }

            $response = $live ? $kms->post('kms/product/createUpdate', $payload) : ['dry_run' => true];

            $after = [];
            $anyVisible = false;
            foreach ($rows as $row) {
                $lookup = $this->lookup($kms, (string) $row['article_number'], (string) ($row['ean'] ?? ''));
                $after[(string) $row['article_number']] = $lookup;
                $anyVisible = $anyVisible || $lookup['articleCount'] > 0 || $lookup['eanCount'] > 0;
            }

            $report[] = [
                'label' => $label,
                'payload' => $payload,
                'response' => $response,
                'before' => $before,
                'after' => $after,
                'anyVisible' => $anyVisible,
            ];

            $this->line($label . ' => ' . ($anyVisible ? 'VISIBLE' : 'NOT_VISIBLE'));
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $path = $dir . '/parent_from_visible_' . $family9 . '.json';
            file_put_contents($path, json_encode([
                'family9' => $family9,
                'mode' => $live ? 'live' : 'dry-run',
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('JSON: ' . $path);
        }

        return self::SUCCESS;
    }

    private function lookup($kms, string $article, string $ean): array
    {
        $articleRows = $this->normalizeRows($kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ]));

        $eanRows = [];
        if ($ean !== '') {
            $eanRows = $this->normalizeRows($kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 10,
                'ean' => $ean,
            ]));
        }

        return [
            'articleCount' => count($articleRows),
            'eanCount' => count($eanRows),
            'snapshot' => $articleRows[0] ?? $eanRows[0] ?? null,
        ];
    }

    private function normalizeRows(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return array_values(array_filter($response['data'], 'is_array'));
        }
        if (is_array($response) && array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return [];
    }
}
