<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use App\Support\Kms\KmsProbeClientBridge;
use Illuminate\Console\Command;

class KmsProbeCloneVisibleVariant extends Command
{
    protected $signature = 'kms:probe:clone-visible-variant
        {seed_article}
        {new_article}
        {--seed-ean=}
        {--new-ean=}
        {--new-size=}
        {--new-color=}
        {--seed-name=}
        {--seed-price=}
        {--seed-purchase-price=}
        {--seed-brand=}
        {--seed-unit=}
        {--seed-color=}
        {--seed-size=}
        {--seed-supplier-name=}
        {--write-json}
        {--debug}
        {--live}';

    protected $description = 'Clone a real visible KMS variant and mutate only the smallest possible fields (article/ean/size/color) to drill the shortest create path.';

    public function handle(KmsClient $kms, KmsProbeClientBridge $bridge): int
    {
        $seedArticle = (string) $this->argument('seed_article');
        $newArticle = (string) $this->argument('new_article');
        $seedEan = (string) ($this->option('seed-ean') ?: '');
        $newEan = (string) ($this->option('new-ean') ?: '');
        $newSize = (string) ($this->option('new-size') ?: '');
        $newColor = (string) ($this->option('new-color') ?: '');
        $debug = (bool) $this->option('debug');
        $live = (bool) $this->option('live');

        $this->line('=== KMS CLONE VISIBLE VARIANT PROBE ===');
        $this->line('Mode       : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Seed       : ' . $seedArticle);
        $this->line('New article: ' . $newArticle);
        $this->line('New ean    : ' . $newEan);

        $seedRows = $bridge->lookupArticle($kms, $seedArticle, $debug);
        if ($seedRows === [] && $seedEan !== '') {
            $seedRows = $bridge->lookupEan($kms, $seedEan, $debug);
        }

        if ($seedRows === []) {
            $seed = $this->seedFromOptions($seedArticle);
            if ($seed === []) {
                $this->error('Seed article not visible in KMS and no explicit seed fields provided: ' . $seedArticle);
                return self::FAILURE;
            }
            $this->warn('Seed article not visible through lookup; using explicit seed fields from options.');
        } else {
            $seed = $seedRows[0];
        }
        $this->line('Seed snapshot:');
        $this->line(json_encode($bridge->compactRow($seed), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $base = [
            'article_number' => $newArticle,
            'articleNumber' => $newArticle,
            'ean' => $newEan,
            'name' => $seed['name'] ?? $newArticle,
            'price' => $seed['price'] ?? 0,
            'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
            'purchasePrice' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
            'brand' => $seed['brand'] ?? null,
            'unit' => $seed['unit'] ?? null,
            'color' => $newColor !== '' ? $newColor : ($seed['color'] ?? null),
            'size' => $newSize !== '' ? $newSize : ($seed['size'] ?? null),
            'supplier_name' => $seed['supplierName'] ?? $seed['supplier_name'] ?? null,
            'supplierName' => $seed['supplierName'] ?? $seed['supplier_name'] ?? null,
        ];

        $variants = [
            'exact_clone_all_visible_fields' => [$base, 'Kopieer het zichtbare seed-product zo exact mogelijk; wijzig alleen article/ean/maat/kleur/naam overrides.'],
            'exact_clone_without_supplier' => [array_diff_key($base, array_flip(['supplier_name', 'supplierName'])), 'Zelfde clone maar zonder supplier-velden.'],
            'snake_only_clone' => [array_diff_key($base, array_flip(['articleNumber', 'purchasePrice', 'supplierName'])), 'Zelfde clone maar zonder camelCase-dubbels.'],
            'article_ean_only' => [[
                'article_number' => $newArticle,
                'articleNumber' => $newArticle,
                'ean' => $newEan,
                'name' => $seed['name'] ?? $newArticle,
                'price' => $seed['price'] ?? 0,
                'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'purchasePrice' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
            ], 'Zo kaal mogelijk: alleen seed basisvelden die al zichtbaar waren, plus nieuw article/ean.'],
            'article_ean_size_only' => [[
                'article_number' => $newArticle,
                'articleNumber' => $newArticle,
                'ean' => $newEan,
                'name' => $seed['name'] ?? $newArticle,
                'price' => $seed['price'] ?? 0,
                'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'purchasePrice' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'size' => $newSize !== '' ? $newSize : ($seed['size'] ?? null),
            ], 'Seed clone met alleen size extra behouden.'],
            'article_ean_color_size' => [[
                'article_number' => $newArticle,
                'articleNumber' => $newArticle,
                'ean' => $newEan,
                'name' => $seed['name'] ?? $newArticle,
                'price' => $seed['price'] ?? 0,
                'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'purchasePrice' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'color' => $newColor !== '' ? $newColor : ($seed['color'] ?? null),
                'size' => $newSize !== '' ? $newSize : ($seed['size'] ?? null),
            ], 'Seed clone met kleur + maat behouden.'],
            'article_ean_brand_unit_color_size' => [[
                'article_number' => $newArticle,
                'articleNumber' => $newArticle,
                'ean' => $newEan,
                'name' => $seed['name'] ?? $newArticle,
                'price' => $seed['price'] ?? 0,
                'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'purchasePrice' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
                'brand' => $seed['brand'] ?? null,
                'unit' => $seed['unit'] ?? null,
                'color' => $newColor !== '' ? $newColor : ($seed['color'] ?? null),
                'size' => $newSize !== '' ? $newSize : ($seed['size'] ?? null),
            ], 'Seed clone met merk/eenheid/kleur/maat, maar zonder supplier-velden.'],
        ];

        $results = [];
        foreach ($variants as $key => [$row, $label]) {
            $this->line(PHP_EOL . '--- ' . $key . ' ---');
            $this->line($label);
            $payload = ['products' => [$row]];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $beforeArticle = $bridge->lookupArticle($kms, $newArticle, $debug);
            $beforeEan = $newEan !== '' ? $bridge->lookupEan($kms, $newEan, $debug) : [];
            $response = $live ? $bridge->createUpdate($kms, $payload) : ['dry_run' => true];
            if ($live) {
                $this->line('createUpdate response: ' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $afterArticle = $bridge->lookupArticle($kms, $newArticle, $debug);
            $afterEan = $newEan !== '' ? $bridge->lookupEan($kms, $newEan, $debug) : [];
            $visible = $afterArticle !== [] || $afterEan !== [];
            $this->line('RESULT=' . ($visible ? 'VISIBLE' : 'NOT_VISIBLE'));
            if ($visible) {
                $snapshot = $afterArticle[0] ?? $afterEan[0] ?? null;
                $this->line('SNAPSHOT=' . json_encode($bridge->compactRow($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $results[$key] = ['visible' => $visible, 'response' => $response];
        }

        $report = [
            'new_article' => $newArticle,
            'tested' => count($results),
            'any_visible' => collect($results)->contains(fn ($r) => ($r['visible'] ?? false) === true),
            'live' => $live,
            'results' => $results,
        ];

        if ($this->option('write-json')) {
            $path = $this->reportPath('clone_visible_variant_' . $newArticle . '.json');
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('JSON: ' . $path);
        }

        $this->line(PHP_EOL . json_encode([
            'new_article' => $newArticle,
            'tested' => count($results),
            'any_visible' => $report['any_visible'],
            'live' => $live,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }



    private function seedFromOptions(string $seedArticle): array
    {
        $row = [
            'articleNumber' => $seedArticle,
            'article_number' => $seedArticle,
            'ean' => (string) ($this->option('seed-ean') ?: ''),
            'name' => (string) ($this->option('seed-name') ?: ''),
            'price' => $this->numericOption('seed-price'),
            'purchasePrice' => $this->numericOption('seed-purchase-price'),
            'purchase_price' => $this->numericOption('seed-purchase-price'),
            'brand' => (string) ($this->option('seed-brand') ?: ''),
            'unit' => (string) ($this->option('seed-unit') ?: ''),
            'color' => (string) ($this->option('seed-color') ?: ''),
            'size' => (string) ($this->option('seed-size') ?: ''),
            'supplierName' => (string) ($this->option('seed-supplier-name') ?: ''),
            'supplier_name' => (string) ($this->option('seed-supplier-name') ?: ''),
        ];

        return array_filter($row, static fn ($v) => $v !== '' && $v !== null);
    }

    private function numericOption(string $key): ?float
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function reportPath(string $filename): string
    {
        $dir = storage_path('app/private/kms_scan/live_family_probes');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $filename;
    }
}
