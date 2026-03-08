<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use App\Support\Kms\KmsProbeClientBridge;
use Illuminate\Console\Command;

class KmsProbeExistingFamilySiblingDrill extends Command
{
    protected $signature = 'kms:probe:existing-family-sibling-drill
        {seed_article}
        {new_article}
        {--seed-ean=}
        {--new-ean=}
        {--new-size=}
        {--family-article=}
        {--basis12=}
        {--type-name=}
        {--brand=}
        {--unit=}
        {--color=}
        {--name=}
        {--description=}
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

    protected $description = 'Drill logical createUpdate combinations for a new sibling inside an existing visible family/basis structure.';

    public function handle(KmsClient $kms, KmsProbeClientBridge $bridge): int
    {
        $seedArticle = (string) $this->argument('seed_article');
        $newArticle = (string) $this->argument('new_article');
        $seedEan = (string) ($this->option('seed-ean') ?: '');
        $newEan = (string) ($this->option('new-ean') ?: '');
        $newSize = (string) ($this->option('new-size') ?: '');
        $familyArticle = (string) ($this->option('family-article') ?: '');
        $basis12 = (string) ($this->option('basis12') ?: '');
        $typeName = (string) ($this->option('type-name') ?: '');
        $brand = (string) ($this->option('brand') ?: '');
        $unit = (string) ($this->option('unit') ?: '');
        $color = (string) ($this->option('color') ?: '');
        $name = (string) ($this->option('name') ?: '');
        $description = (string) ($this->option('description') ?: $name);
        $debug = (bool) $this->option('debug');
        $live = (bool) $this->option('live');

        $this->line('=== KMS EXISTING FAMILY SIBLING DRILL ===');
        $this->line('Mode         : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Seed article : ' . $seedArticle);
        $this->line('New article  : ' . $newArticle);
        $this->line('Family(11)   : ' . $familyArticle);
        $this->line('Basis(12)    : ' . $basis12);

        $seedRows = $bridge->lookupArticle($kms, $seedArticle, $debug);
        if ($seedRows === [] && $seedEan !== '') {
            $seedRows = $bridge->lookupEan($kms, $seedEan, $debug);
        }

        if ($seedRows === []) {
            $seed = $this->seedFromOptions($seedArticle);
            if ($seed === []) {
                $this->warn('Seed article not visible through this lookup path, continuing with explicit options/forced payload.');
                $seed = [];
            } else {
                $this->warn('Seed article not visible through lookup; using explicit seed fields from options.');
            }
        } else {
            $seed = $seedRows[0];
            $this->line('Seed snapshot:');
            $this->line(json_encode($bridge->compactRow($seed), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $base = [
            'article_number' => $newArticle,
            'ean' => $newEan,
            'name' => $name ?: ($seed['name'] ?? $newArticle),
            'description' => $description,
            'price' => $seed['price'] ?? 12.34,
            'purchase_price' => $seed['purchasePrice'] ?? $seed['purchase_price'] ?? 0,
            'brand' => $brand ?: ($seed['brand'] ?? ''),
            'unit' => $unit ?: ($seed['unit'] ?? ''),
            'color' => $color ?: ($seed['color'] ?? ''),
            'size' => $newSize,
            'type_number' => $familyArticle,
            'type_name' => $typeName,
            '_seed_article_context' => $seedArticle,
            'supplier_name' => $seed['supplierName'] ?? $seed['supplier_name'] ?? '',
        ];

        $variants = [
            'full_snake' => [$base, 'Volledige snake_case variant met alle logische sibling velden.'],
            'full_dual_keys' => [[...$base, 'articleNumber' => $newArticle, 'purchasePrice' => $base['purchase_price'], 'typeNumber' => $familyArticle, 'typeName' => $typeName], 'Zelfde payload maar met dual keys snake_case + camelCase.'],
            'type_only_core' => [[
                'article_number' => $newArticle,
                'ean' => $newEan,
                'name' => $base['name'],
                'price' => $base['price'],
                'purchase_price' => $base['purchase_price'],
                'brand' => $base['brand'],
                'unit' => $base['unit'],
                'type_number' => $familyArticle,
                'type_name' => $typeName,
                '_seed_article_context' => $seedArticle,
                'supplier_name' => $base['supplier_name'],
            ], 'Minimal core met type-paar, merk, eenheid en ean.'],
            'type_number_without_type_name' => [[
                'article_number' => $newArticle,
                'ean' => $newEan,
                'name' => $base['name'],
                'description' => $description,
                'price' => $base['price'],
                'purchase_price' => $base['purchase_price'],
                'brand' => $base['brand'],
                'unit' => $base['unit'],
                'color' => $base['color'],
                'size' => $base['size'],
                'type_number' => $familyArticle,
                '_seed_article_context' => $seedArticle,
                'supplier_name' => $base['supplier_name'],
            ], 'Controleren of type_number alleen genoeg is binnen bestaande familie.'],
            'basis_then_new_variant' => [[
                [
                    'article_number' => $basis12,
                    'ean' => $newEan,
                    'name' => $base['name'],
                    'description' => $description,
                    'price' => $base['price'],
                    'purchase_price' => $base['purchase_price'],
                    'brand' => $base['brand'],
                    'unit' => $base['unit'],
                    'color' => $base['color'],
                    'type_number' => $familyArticle,
                    'type_name' => $typeName,
                ],
                $base,
            ], 'Eerst basis12 record, daarna nieuwe sibling in hetzelfde request.'],
            'basis_then_new_variant_dual_keys' => [[
                [
                    'article_number' => $basis12,
                    'ean' => $newEan,
                    'name' => $base['name'],
                    'description' => $description,
                    'price' => $base['price'],
                    'purchase_price' => $base['purchase_price'],
                    'brand' => $base['brand'],
                    'unit' => $base['unit'],
                    'color' => $base['color'],
                    'type_number' => $familyArticle,
                    'type_name' => $typeName,
                    'articleNumber' => $basis12,
                    'purchasePrice' => $base['purchase_price'],
                    'typeNumber' => $familyArticle,
                    'typeName' => $typeName,
                ],
                [...$base, 'articleNumber' => $newArticle, 'purchasePrice' => $base['purchase_price'], 'typeNumber' => $familyArticle, 'typeName' => $typeName],
            ], 'Basis12 + sibling met dual keys.'],
            'full_snake_with_seed_context' => [$base, 'Volledige sibling payload plus seed context marker voor debugging.'],
        ];

        $results = [];
        foreach ($variants as $key => [$variantRows, $label]) {
            $this->line(PHP_EOL . '--- ' . $key . ' ---');
            $this->line($label);

            $rows = isset($variantRows[0]) && is_array($variantRows[0]) && array_is_list($variantRows) ? $variantRows : [$variantRows];
            $payload = ['products' => $rows];
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
                $results[$key] = ['visible' => true, 'snapshot' => $bridge->compactRow($snapshot), 'response' => $response];
                break;
            }

            $results[$key] = [
                'visible' => false,
                'before_article_count' => count($beforeArticle),
                'before_ean_count' => count($beforeEan),
                'after_article_count' => count($afterArticle),
                'after_ean_count' => count($afterEan),
                'response' => $response,
            ];
        }

        $report = [
            'new_article' => $newArticle,
            'tested' => count($results),
            'any_visible' => collect($results)->contains(fn ($r) => ($r['visible'] ?? false) === true),
            'live' => $live,
            'results' => $results,
        ];

        if ($this->option('write-json')) {
            $path = $this->reportPath('existing_family_sibling_drill_' . $newArticle . '.json');
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
