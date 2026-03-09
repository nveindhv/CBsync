<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use App\Support\StoragePathResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsExhaustiveFamilyBootstrapProbe extends Command
{
    protected $signature = 'kms:probe:family-bootstrap-exhaustive
        {family : Family identifier, usually 9 digits}
        {--children= : Comma-separated 15-digit child articles to test}
        {--bases11= : Comma-separated 11-digit basis articles to test}
        {--bases12= : Comma-separated 12-digit basis articles to test}
        {--dry-run : Only verify, do not post createUpdate}
        {--write-json : Write report to storage}
        {--debug : Verbose output}';

    protected $description = 'Exhaustively probe 9/11/12/15 family bootstrap combinations for a clean ERP family.';

    public function handle(KmsClient $kms): int
    {
        $family9 = substr(trim((string) $this->argument('family')), 0, 9);
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');

        $erpFamily = $this->loadErpFamily($family9);
        $kmsFamily = $this->loadKmsFamily($family9);
        $erpRows = $this->loadErpRows($family9);

        if (! is_array($erpFamily)) {
            $this->error('ERP family niet gevonden: ' . $family9);
            return self::FAILURE;
        }

        $erpArticles = array_values(array_unique(array_map('strval', (array) ($erpFamily['articles'] ?? []))));
        sort($erpArticles);
        $kmsArticles = array_values(array_unique(array_map('strval', (array) ($kmsFamily['articles'] ?? []))));
        sort($kmsArticles);
        $missing = array_values(array_diff($erpArticles, $kmsArticles));

        $children = $this->csv((string) ($this->option('children') ?? ''));
        if ($children === []) {
            $children = $this->pickChildren($missing, $erpRows, 2);
        }
        if ($children === []) {
            $this->error('Geen missende 15-digit children gevonden voor family ' . $family9);
            return self::FAILURE;
        }

        $bases11 = $this->csv((string) ($this->option('bases11') ?? ''));
        if ($bases11 === []) {
            $bases11 = array_values(array_unique(array_map(static fn (string $child) => substr($child, 0, 11), $children)));
        }

        $bases12 = $this->csv((string) ($this->option('bases12') ?? ''));
        if ($bases12 === []) {
            $bases12 = array_values(array_unique(array_map(static fn (string $child) => substr($child, 0, 12), $children)));
        }

        $sampleName = trim((string) ($this->firstName((array) ($erpFamily['names'] ?? [])) ?: ($kmsFamily['sample']['name'] ?? $family9)));
        $sampleBrand = trim((string) ($kmsFamily['sample']['brand'] ?? 'TRICORP'));
        $sampleUnit = trim((string) ($kmsFamily['sample']['unit'] ?? 'STK'));

        $this->line('=== KMS EXHAUSTIVE FAMILY BOOTSTRAP PROBE ===');
        $this->line('Family : ' . $family9);
        $this->line('Mode   : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->line('Children: ' . implode(', ', $children));
        $this->line('Bases11 : ' . implode(', ', $bases11));
        $this->line('Bases12 : ' . implode(', ', $bases12));
        $this->newLine();

        $report = [
            'family' => $family9,
            'mode' => $dryRun ? 'dry-run' : 'live',
            'children' => $children,
            'bases11' => $bases11,
            'bases12' => $bases12,
            'erp_articles_count' => count($erpArticles),
            'kms_articles_count' => count($kmsArticles),
            'missing_articles' => $missing,
            'scenarios' => [],
        ];

        foreach ($children as $child) {
            if (! isset($erpRows[$child])) {
                $this->warn('ERP row ontbreekt voor child: ' . $child);
                continue;
            }

            $base11 = substr($child, 0, 11);
            $base12 = substr($child, 0, 12);
            $variant15 = $this->variantPayload($child, $erpRows[$child], $sampleName, $sampleBrand, $sampleUnit, $base11);
            $parent9 = $this->parentPayload($family9, $sampleName, $sampleBrand, $sampleUnit, $family9);
            $parent11 = $this->parentPayload($base11, $sampleName, $sampleBrand, $sampleUnit, $base11);
            $parent12 = $this->parentPayload($base12, $sampleName, $sampleBrand, $sampleUnit, $base11);
            $self15 = $this->parentPayload($child, $sampleName, $sampleBrand, $sampleUnit, $base11);
            $ean = (string) data_get($variant15, 'products.0.ean', '');

            $scenarios = [
                ['name' => '15 only [' . $child . ']', 'steps' => [
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '9 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent9', 'payload' => $parent9, 'article' => $family9, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '11 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent11', 'payload' => $parent11, 'article' => $base11, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '12 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent12', 'payload' => $parent12, 'article' => $base12, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '9 + 11 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent9', 'payload' => $parent9, 'article' => $family9, 'ean' => ''],
                    ['name' => 'parent11', 'payload' => $parent11, 'article' => $base11, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '9 + 12 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent9', 'payload' => $parent9, 'article' => $family9, 'ean' => ''],
                    ['name' => 'parent12', 'payload' => $parent12, 'article' => $base12, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '11 + 12 + 15 [' . $child . ']', 'steps' => [
                    ['name' => 'parent11', 'payload' => $parent11, 'article' => $base11, 'ean' => ''],
                    ['name' => 'parent12', 'payload' => $parent12, 'article' => $base12, 'ean' => ''],
                    ['name' => 'variant15', 'payload' => $variant15, 'article' => $child, 'ean' => $ean],
                ]],
                ['name' => '15 as self-parent [' . $child . ']', 'steps' => [
                    ['name' => 'self15', 'payload' => $self15, 'article' => $child, 'ean' => $ean],
                ]],
            ];

            foreach ($scenarios as $scenario) {
                $this->line('---------- ' . $scenario['name'] . ' ----------');
                $scenarioReport = [
                    'name' => $scenario['name'],
                    'child' => $child,
                    'steps' => [],
                ];

                foreach ($scenario['steps'] as $step) {
                    $before = $this->lookup($kms, $step['article'], $step['ean']);
                    $this->line('before article=' . $step['article'] . ' count=' . $before['count_article']);
                    if ($step['ean'] !== '') {
                        $this->line('before ean=' . $step['ean'] . ' count=' . $before['count_ean']);
                    }

                    $correlationId = (string) Str::uuid();
                    $response = null;
                    $error = null;

                    if (! $dryRun) {
                        try {
                            $response = $kms->post('kms/product/createUpdate', $step['payload'], $correlationId);
                        } catch (\Throwable $e) {
                            $error = $e->getMessage();
                        }
                    }

                    $after = $this->lookup($kms, $step['article'], $step['ean']);
                    $this->line('after  article=' . $step['article'] . ' count=' . $after['count_article']);
                    if ($step['ean'] !== '') {
                        $this->line('after  ean=' . $step['ean'] . ' count=' . $after['count_ean']);
                    }

                    $scenarioReport['steps'][] = [
                        'name' => $step['name'],
                        'article' => $step['article'],
                        'ean' => $step['ean'],
                        'correlation_id' => $correlationId,
                        'payload' => $step['payload'],
                        'before' => $before,
                        'after' => $after,
                        'created_now' => ($before['count_article'] === 0 && $after['count_article'] > 0)
                            || ($step['ean'] !== '' && $before['count_ean'] === 0 && $after['count_ean'] > 0),
                        'response' => $response,
                        'error' => $error,
                    ];
                }

                $report['scenarios'][] = $scenarioReport;
                $this->newLine();
            }
        }

        $path = StoragePathResolver::ensurePrivateDir('kms_scan/live_family_probes')
            . DIRECTORY_SEPARATOR
            . 'exhaustive_family_probe_' . $family9 . '_' . now()->format('Ymd_His') . '.json';

        if ((bool) $this->option('write-json')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->line('REPORT JSON : ' . $path);

        return self::SUCCESS;
    }

    private function parentPayload(string $article, string $name, string $brand, string $unit, string $typeNumber): array
    {
        return [
            'products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'type_name' => '',
                'typeName' => '',
            ]],
        ];
    }

    private function variantPayload(string $article, array $row, string $fallbackName, string $fallbackBrand, string $fallbackUnit, string $typeNumber): array
    {
        $ean = trim((string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? ''));
        if ($ean === '00000000000000') {
            $ean = '';
        }

        $payload = [
            'products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'ean' => $ean !== '' ? $ean : null,
                'name' => trim((string) ($row['description'] ?? '')) ?: $fallbackName,
                'brand' => trim((string) ($row['searchName'] ?? '')) ?: $fallbackBrand,
                'unit' => trim((string) ($row['unitCode'] ?? '')) ?: $fallbackUnit,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'purchase_price' => $row['costPrice'] ?? null,
            ]],
        ];

        $payload['products'][0] = array_filter(
            $payload['products'][0],
            static fn ($value) => $value !== null && $value !== ''
        );

        return $payload;
    }

    private function lookup(KmsClient $kms, string $article, string $ean): array
    {
        $countArticle = 0;
        $countEan = 0;
        $rowsArticle = [];
        $rowsEan = [];

        try {
            $resp = $kms->post('kms/product/getProducts', ['articleNumber' => $article]);
            $rowsArticle = $this->rows($resp);
            $countArticle = count($rowsArticle);
        } catch (\Throwable $e) {
        }

        if ($ean !== '') {
            try {
                $resp = $kms->post('kms/product/getProducts', ['ean' => $ean]);
                $rowsEan = $this->rows($resp);
                $countEan = count($rowsEan);
            } catch (\Throwable $e) {
            }
        }

        return [
            'count_article' => $countArticle,
            'count_ean' => $countEan,
            'article_sample' => $this->compactRow($rowsArticle[0] ?? null),
            'ean_sample' => $this->compactRow($rowsEan[0] ?? null),
        ];
    }

    private function rows($resp): array
    {
        if (is_array($resp['products'] ?? null)) {
            return array_values(array_filter($resp['products'], 'is_array'));
        }

        if (is_array($resp['result'] ?? null)) {
            $result = $resp['result'];
            if (isset($result[0]) && is_array($result[0])) {
                return array_values(array_filter($result, 'is_array'));
            }
        }

        return [];
    }

    private function compactRow($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $keys = [
            'id', 'articleNumber', 'article_number', 'ean', 'name', 'price', 'purchasePrice', 'purchase_price',
            'unit', 'brand', 'color', 'size', 'supplierName', 'supplier_name', 'typeNumber', 'type_number',
            'typeName', 'type_name', 'modifyDate',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
            }
        }

        return $out;
    }

    private function csv(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, static fn (string $part) => $part !== ''));
        return array_values(array_unique($parts));
    }

    private function pickChildren(array $missing, array $erpRows, int $max): array
    {
        $picked = [];
        foreach ($missing as $article) {
            $row = $erpRows[$article] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $ean = trim((string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? ''));
            if ($ean === '' || $ean === '00000000000000') {
                continue;
            }
            $picked[] = (string) $article;
            if (count($picked) >= $max) {
                break;
            }
        }
        return $picked;
    }

    private function loadErpFamily(string $family9): ?array
    {
        $path = StoragePathResolver::resolve('erp_dump/combined_family_index.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $row) {
            if (is_array($row) && (string) ($row['family'] ?? '') === $family9) {
                return $row;
            }
        }
        return null;
    }

    private function loadKmsFamily(string $family9): array
    {
        $path = StoragePathResolver::resolve('kms_scan/combined_products_index.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_array($decoded['families'] ?? null)) {
            return [];
        }
        return (array) ($decoded['families'][$family9] ?? []);
    }

    private function loadErpRows(string $family9): array
    {
        $files = StoragePathResolver::globAll('erp_dump/prefix_matches_*.json');
        $rows = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = (string) ($row['productCode'] ?? '');
                if ($code === '' || ! str_starts_with($code, $family9)) {
                    continue;
                }
                if (! isset($rows[$code])) {
                    $rows[$code] = $row;
                }
            }
        }
        return $rows;
    }

    private function firstName(array $names): ?string
    {
        if ($names === []) {
            return null;
        }
        arsort($names);
        return (string) array_key_first($names);
    }
}
