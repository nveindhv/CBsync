<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsExhaustiveFamilyBootstrapProbe extends Command
{
    protected $signature = 'kms:probe:family-bootstrap-exhaustive
        {family : Family identifier, usually 9 digits}
        {--children= : Comma-separated 15-digit child articles to test}
        {--bases= : Comma-separated 12-digit basis articles to test}
        {--dry-run : Only verify, do not post createUpdate}
        {--write-json : Write report to storage}
        {--debug : Verbose output}';

    protected $description = 'Probe all relevant 9/12/15 parent-child structure combinations for one family.';

    public function handle(KmsClient $kms): int
    {
        $family = trim((string) $this->argument('family'));
        $family9 = substr($family, 0, 9);
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
        $kmsArticles = array_values(array_unique(array_map('strval', (array) ($kmsFamily['articles'] ?? []))));
        $missing = array_values(array_diff($erpArticles, $kmsArticles));

        $explicitChildren = $this->csv($this->option('children'));
        $children = $explicitChildren !== [] ? $explicitChildren : $this->pickChildren($missing, $erpRows, 4);
        if ($children === []) {
            $this->error('Geen missende kinderen gevonden.');
            return self::FAILURE;
        }

        $explicitBases = $this->csv($this->option('bases'));
        $bases = $explicitBases !== [] ? $explicitBases : $this->deriveBases($children);

        $this->line('=== KMS EXHAUSTIVE FAMILY BOOTSTRAP PROBE ===');
        $this->line('Family : ' . $family9);
        $this->line('Mode   : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->line('Children: ' . implode(', ', $children));
        $this->line('Bases   : ' . implode(', ', $bases));
        $this->newLine();

        $sampleName = (string) ($kmsFamily['sample']['name'] ?? $family9);
        $sampleBrand = (string) ($kmsFamily['sample']['brand'] ?? '');
        $sampleUnit = (string) ($kmsFamily['sample']['unit'] ?? 'STK');

        $scenarios = [];
        foreach ($children as $child) {
            if (! isset($erpRows[$child])) {
                continue;
            }
            $base12 = substr($child, 0, 12);
            $childPayload = $this->variantPayload($child, $erpRows[$child], $sampleName, $sampleBrand, $sampleUnit, $family9);
            $parent9 = $this->parentPayload($family9, $sampleName, $sampleBrand, $sampleUnit, $family9);
            $parent12 = $this->parentPayload($base12, $sampleName, $sampleBrand, $sampleUnit, $family9);

            $scenarios[] = ['name' => '9 + 15 [' . $child . ']', 'steps' => [
                ['name' => 'parent9', 'payload' => $parent9, 'article' => $family9, 'ean' => ''],
                ['name' => 'child15', 'payload' => $childPayload, 'article' => $child, 'ean' => (string) data_get($childPayload, 'products.0.ean', '')],
            ]];

            $scenarios[] = ['name' => '12 + 15 [' . $child . ']', 'steps' => [
                ['name' => 'parent12', 'payload' => $parent12, 'article' => $base12, 'ean' => ''],
                ['name' => 'child15', 'payload' => $childPayload, 'article' => $child, 'ean' => (string) data_get($childPayload, 'products.0.ean', '')],
            ]];

            $scenarios[] = ['name' => '9 + 12 + 15 [' . $child . ']', 'steps' => [
                ['name' => 'parent9', 'payload' => $parent9, 'article' => $family9, 'ean' => ''],
                ['name' => 'parent12', 'payload' => $parent12, 'article' => $base12, 'ean' => ''],
                ['name' => 'child15', 'payload' => $childPayload, 'article' => $child, 'ean' => (string) data_get($childPayload, 'products.0.ean', '')],
            ]];

            $self15Parent = $this->parentPayload($child, $sampleName, $sampleBrand, $sampleUnit, $family9);
            $scenarios[] = ['name' => '15 as self-parent [' . $child . ']', 'steps' => [
                ['name' => 'self15', 'payload' => $self15Parent, 'article' => $child, 'ean' => (string) data_get($childPayload, 'products.0.ean', '')],
            ]];
        }

        $report = [
            'family' => $family9,
            'mode' => $dryRun ? 'dry-run' : 'live',
            'children' => $children,
            'bases' => $bases,
            'scenarios' => [],
        ];

        foreach ($scenarios as $scenario) {
            $this->line('---------- ' . $scenario['name'] . ' ----------');
            $scenarioReport = ['name' => $scenario['name'], 'steps' => []];

            foreach ($scenario['steps'] as $step) {
                $before = $this->lookup($kms, $step['article'], $step['ean']);
                $this->line('before article=' . $step['article'] . ' count=' . $before['count_article']);
                if ($step['ean'] !== '') {
                    $this->line('before ean=' . $step['ean'] . ' count=' . $before['count_ean']);
                }

                $resp = null;
                $error = null;
                $cid = (string) Str::uuid();
                if (! $dryRun) {
                    try {
                        $resp = $kms->post('kms/product/createUpdate', $step['payload'], $cid);
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
                    'correlation_id' => $cid,
                    'before' => $before,
                    'after' => $after,
                    'created_now' => ($before['count_article'] === 0 && $after['count_article'] > 0)
                        || ($step['ean'] !== '' && $before['count_ean'] === 0 && $after['count_ean'] > 0),
                    'response' => $resp,
                    'error' => $error,
                ];
            }
            $report['scenarios'][] = $scenarioReport;
            $this->newLine();
        }

        $path = storage_path('app/private/kms_scan/live_family_probes/exhaustive_family_probe_' . $family9 . '_' . now()->format('Ymd_His') . '.json');
        if ((bool) $this->option('write-json')) {
            @mkdir(dirname($path), 0777, true);
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

    private function variantPayload(string $article, array $row, string $name, string $brand, string $unit, string $typeNumber): array
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
                'name' => trim((string) ($row['description'] ?? '')) ?: $name,
                'brand' => trim((string) ($row['searchName'] ?? '')) ?: $brand,
                'unit' => trim((string) ($row['unitCode'] ?? '')) ?: $unit,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'purchase_price' => $row['costPrice'] ?? null,
            ]],
        ];
        $payload['products'][0] = array_filter($payload['products'][0], static fn($v) => $v !== null && $v !== '');
        return $payload;
    }

    private function lookup(KmsClient $kms, string $article, string $ean): array
    {
        $countArticle = 0;
        $countEan = 0;
        try {
            $resp = $kms->post('kms/product/getProducts', ['articleNumber' => $article]);
            $countArticle = count($this->rows($resp));
        } catch (\Throwable $e) {
        }
        if ($ean !== '') {
            try {
                $resp = $kms->post('kms/product/getProducts', ['ean' => $ean]);
                $countEan = count($this->rows($resp));
            } catch (\Throwable $e) {
            }
        }
        return ['count_article' => $countArticle, 'count_ean' => $countEan];
    }

    private function rows($resp): array
    {
        if (is_array($resp['products'] ?? null)) {
            return array_values(array_filter($resp['products'], 'is_array'));
        }
        if (is_array($resp) && isset($resp[0]) && is_array($resp[0])) {
            return array_values(array_filter($resp, 'is_array'));
        }
        return [];
    }

    private function loadErpFamily(string $family9): ?array
    {
        $path = is_file(storage_path('app/private/erp_dump/combined_family_index.json'))
            ? storage_path('app/private/erp_dump/combined_family_index.json')
            : storage_path('app/erp_dump/combined_family_index.json');
        if (! is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true);
        foreach ((array) $decoded as $row) {
            if (is_array($row) && (string) ($row['family'] ?? '') === $family9) return $row;
        }
        return null;
    }

    private function loadKmsFamily(string $family9): array
    {
        $path = is_file(storage_path('app/private/kms_scan/combined_products_index.json'))
            ? storage_path('app/private/kms_scan/combined_products_index.json')
            : storage_path('app/kms_scan/combined_products_index.json');
        if (! is_file($path)) return [];
        $decoded = json_decode((string) file_get_contents($path), true);
        return (array) (($decoded['families'] ?? [])[$family9] ?? []);
    }

    private function loadErpRows(string $family9): array
    {
        $rows = [];
        foreach ((glob(storage_path('app/erp_dump/prefix_matches_*.json')) ?: []) as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            foreach ((array) $decoded as $row) {
                if (! is_array($row)) continue;
                $code = (string) ($row['productCode'] ?? '');
                if ($code !== '' && str_starts_with($code, $family9) && ! isset($rows[$code])) {
                    $rows[$code] = $row;
                }
            }
        }
        return $rows;
    }

    private function pickChildren(array $missing, array $rows, int $max): array
    {
        $scored = [];
        foreach ($missing as $article) {
            $row = $rows[$article] ?? [];
            $ean = trim((string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? ''));
            $score = ($ean !== '' && $ean !== '00000000000000') ? 1 : 0;
            $scored[] = ['article' => $article, 'score' => $score];
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_values(array_map(static fn($x) => $x['article'], $scored)), 0, $max);
    }

    private function deriveBases(array $children): array
    {
        return array_values(array_unique(array_map(static fn($x) => substr((string) $x, 0, 12), $children)));
    }

    private function csv($value): array
    {
        $parts = array_map('trim', explode(',', (string) $value));
        return array_values(array_filter($parts, static fn($x) => $x !== ''));
    }
}
