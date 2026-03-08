<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class KmsPrepFamilyLiveProbe extends Command
{
    protected $signature = 'kms:prep:family-live-probe
        {family : Family identifier used in filenames (usually 9 digits)}
        {--child= : Explicit full child articleNumber/productCode}
        {--sibling= : Explicit full sibling articleNumber/productCode}
        {--write-json : Write generated seed payloads to storage}
        {--debug : Verbose output}';

    protected $description = 'Generate seed payload JSONs for live family createUpdate probes from ERP/KMS storage dumps.';

    public function handle(): int
    {
        $familyInput = trim((string) $this->argument('family'));
        $family9 = substr($familyInput, 0, 9);
        $writeJson = (bool) $this->option('write-json');
        $debug = (bool) $this->option('debug');

        $erpIndex = $this->readJson($this->firstExisting([
            storage_path('app/private/erp_dump/combined_family_index.json'),
            storage_path('app/erp_dump/combined_family_index.json'),
        ]));

        $kmsIndex = $this->readJson($this->firstExisting([
            storage_path('app/private/kms_scan/combined_products_index.json'),
            storage_path('app/kms_scan/combined_products_index.json'),
        ]));

        if (! is_array($erpIndex) || ! is_array($kmsIndex)) {
            $this->error('Combined ERP/KMS index ontbreekt of is ongeldig.');
            return self::FAILURE;
        }

        $erpFamily = $this->findFamilyRow($erpIndex, $family9);
        $kmsFamily = $this->findKmsFamily($kmsIndex, $family9);

        if (! is_array($erpFamily)) {
            $this->error('ERP family niet gevonden voor ' . $family9);
            return self::FAILURE;
        }

        $erpRows = $this->loadErpRows($family9);
        $erpArticles = array_values(array_unique(array_map('strval', (array) ($erpFamily['articles'] ?? []))));
        sort($erpArticles);
        $kmsArticles = array_values(array_unique(array_map('strval', (array) ($kmsFamily['articles'] ?? []))));
        sort($kmsArticles);
        $missing = array_values(array_diff($erpArticles, $kmsArticles));

        $child = trim((string) ($this->option('child') ?? ''));
        $sibling = trim((string) ($this->option('sibling') ?? ''));

        $preferred = $this->pickPreferredMissing($missing, $erpRows);
        if ($child === '' && isset($preferred[0])) {
            $child = $preferred[0];
        }
        if ($sibling === '' && isset($preferred[1])) {
            $sibling = $preferred[1];
        }
        if ($child === '' && isset($missing[0])) {
            $child = $missing[0];
        }
        if ($sibling === '' && isset($missing[1])) {
            $sibling = $missing[1];
        }

        if ($child === '' || $sibling === '') {
            $this->error('Kon child/sibling niet bepalen. Geef --child en --sibling mee.');
            return self::FAILURE;
        }
        if (! isset($erpRows[$child], $erpRows[$sibling])) {
            $this->error('ERP rows voor child/sibling ontbreken in prefix_matches dumps.');
            return self::FAILURE;
        }

        $parentSeed = $this->readJson($this->firstExisting([
            storage_path('app/private/kms_scan/parent_payload_' . $family9 . '.json'),
            storage_path('app/kms_scan/parent_payload_' . $family9 . '.json'),
        ]));

        $candidate = is_array($parentSeed) ? ($parentSeed['candidate_parent_payload'] ?? null) : null;
        $fallbackName = (string) data_get($candidate, 'products.0.name', (string) ($kmsFamily['sample']['name'] ?? $family9));
        $fallbackBrand = (string) data_get($candidate, 'products.0.brand', (string) ($kmsFamily['sample']['brand'] ?? ''));
        $fallbackUnit = (string) data_get($candidate, 'products.0.unit', (string) ($kmsFamily['sample']['unit'] ?? 'STK'));

        $parentPayload = is_array($candidate) ? $candidate : [
            'products' => [[
                'article_number' => $family9,
                'articleNumber' => $family9,
                'name' => $fallbackName,
                'brand' => $fallbackBrand,
                'unit' => $fallbackUnit,
                'type_number' => $family9,
                'typeNumber' => $family9,
                'type_name' => '',
                'typeName' => '',
            ]],
        ];

        $childPayload = $this->buildVariantPayload($child, $erpRows[$child], $fallbackName, $fallbackBrand, $fallbackUnit, $family9);
        $siblingPayload = $this->buildVariantPayload($sibling, $erpRows[$sibling], $fallbackName, $fallbackBrand, $fallbackUnit, $family9);

        $dir = storage_path('app/private/kms_scan/live_family_probes');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $metaPath = $dir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '.json';
        $parentPath = $dir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_parent_payload.json';
        $childPath = $dir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_child_payload.json';
        $siblingPath = $dir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_sibling_payload.json';

        $meta = [
            'family' => $familyInput,
            'family9' => $family9,
            'erp_article_count' => count($erpArticles),
            'kms_article_count' => count($kmsArticles),
            'missing_articles' => $missing,
            'selected_child' => $child,
            'selected_sibling' => $sibling,
        ];

        $this->info('Prepared live probe seeds for family ' . $familyInput);
        $this->line('family9            : ' . $family9);
        $this->line('ERP articles        : ' . count($erpArticles));
        $this->line('KMS scanned         : ' . count($kmsArticles));
        $this->line('missing (ERP-KMS)   : ' . count($missing));
        $this->line('selected child      : ' . $child);
        $this->line('selected sibling    : ' . $sibling);

        if ($debug) {
            $this->line('Parent payload: ' . json_encode($parentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('Child payload: ' . json_encode($childPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('Sibling payload: ' . json_encode($siblingPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($writeJson) {
            file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($parentPath, json_encode($parentPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($childPath, json_encode($childPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($siblingPath, json_encode($siblingPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('WROTE: ' . $metaPath);
            $this->line('WROTE: ' . $parentPath);
            $this->line('WROTE: ' . $childPath);
            $this->line('WROTE: ' . $siblingPath);
        }

        return self::SUCCESS;
    }

    private function buildVariantPayload(string $article, array $row, string $fallbackName, string $fallbackBrand, string $fallbackUnit, string $typeNumber): array
    {
        $ean = trim((string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? ''));
        if ($ean === '00000000000000') {
            $ean = '';
        }

        $payload = [
            'article_number' => $article,
            'articleNumber' => $article,
            'name' => trim((string) ($row['description'] ?? '')) ?: $fallbackName,
            'brand' => trim((string) ($row['searchName'] ?? '')) ?: $fallbackBrand,
            'unit' => trim((string) ($row['unitCode'] ?? '')) ?: $fallbackUnit,
            'type_number' => $typeNumber,
            'typeNumber' => $typeNumber,
            'purchase_price' => $row['costPrice'] ?? null,
            'ean' => $ean !== '' ? $ean : null,
        ];

        $payload = array_filter($payload, static fn ($v) => $v !== null && $v !== '');

        return ['products' => [$payload]];
    }

    private function pickPreferredMissing(array $missing, array $rows): array
    {
        $scored = [];
        foreach ($missing as $article) {
            $row = $rows[$article] ?? [];
            $ean = trim((string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? ''));
            $score = ($ean !== '' && $ean !== '00000000000000') ? 1 : 0;
            $scored[] = ['article' => $article, 'score' => $score];
        }

        usort($scored, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_values(array_map(static fn ($x) => $x['article'], $scored));
    }

    private function loadErpRows(string $family9): array
    {
        $files = glob(storage_path('app/erp_dump/prefix_matches_*.json')) ?: [];
        $rows = [];
        foreach ($files as $file) {
            $decoded = $this->readJson($file);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = (string) ($row['productCode'] ?? '');
                if ($code !== '' && str_starts_with($code, $family9) && ! isset($rows[$code])) {
                    $rows[$code] = $row;
                }
            }
        }
        return $rows;
    }

    private function findFamilyRow(array $rows, string $family9): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['family'] ?? '') === $family9) {
                return $row;
            }
        }
        return null;
    }

    private function findKmsFamily(array $decoded, string $family9): array
    {
        $families = is_array($decoded['families'] ?? null) ? $decoded['families'] : [];
        return (array) ($families[$family9] ?? []);
    }

    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function readJson(?string $path)
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }
}
