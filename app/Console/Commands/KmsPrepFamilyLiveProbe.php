<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsPrepFamilyLiveProbe extends Command
{
    protected $signature = 'kms:prep:family-live-probe
        {family : Family identifier used in filenames (usually 9 or 11 digits)}
        {--child= : Explicit full child articleNumber/productCode}
        {--sibling= : Explicit full sibling articleNumber/productCode}
        {--write-json : Write generated seed payloads to storage}
        {--debug : Verbose output}';

    protected $description = 'Generate seed payload JSONs for live family createUpdate probes from existing storage dumps.';

    public function handle(): int
    {
        $familyInput = trim((string) $this->argument('family'));
        if ($familyInput === '') {
            $this->error('family argument is required');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $writeJson = (bool) $this->option('write-json');
        $family9 = substr($familyInput, 0, 9);

        $erpFamilies = $this->loadErpFamilies();
        $kmsFamilies = $this->loadKmsFamilies();

        $erpFamily = $erpFamilies[$family9] ?? null;
        if (! is_array($erpFamily)) {
            $this->error('ERP family not found in combined_family_index.json: ' . $family9);
            $this->line('Run first: php artisan erp:families:combine');
            return self::FAILURE;
        }

        $kmsFamily = $kmsFamilies[$family9] ?? null;

        $erpArticles = array_values(array_filter((array) ($erpFamily['articles'] ?? []), 'is_string'));
        sort($erpArticles);
        $kmsArticles = array_values(array_filter((array) (($kmsFamily['articles'] ?? []) ?: []), 'is_string'));
        sort($kmsArticles);
        $missing = array_values(array_diff($erpArticles, $kmsArticles));

        $child = trim((string) ($this->option('child') ?? ''));
        $sibling = trim((string) ($this->option('sibling') ?? ''));

        if ($child === '' || $sibling === '') {
            if (count($missing) >= 2) {
                $child = $child !== '' ? $child : $missing[0];
                $sibling = $sibling !== '' ? $sibling : $missing[1];
            } else {
                $child = $child !== '' ? $child : ($erpArticles[0] ?? '');
                $sibling = $sibling !== '' ? $sibling : ($erpArticles[1] ?? '');
            }
        }

        if ($child === '' || $sibling === '') {
            $this->error('Could not determine child/sibling. Provide --child and --sibling, or ensure ERP family has >=2 articles.');
            return self::FAILURE;
        }

        $erpRows = $this->loadErpRowsForFamily($family9);
        if (! isset($erpRows[$child]) || ! isset($erpRows[$sibling])) {
            $this->error('ERP rows missing for selected child/sibling in prefix_matches dumps.');
            $this->line('Need rows for: ' . $child . ' and ' . $sibling);
            $this->line('Ensure you ran: php artisan erp:find:products --prefixes=' . $family9 . ' ...');
            return self::FAILURE;
        }

        $kmsSample = is_array($kmsFamily) ? (array) ($kmsFamily['sample'] ?? []) : [];
        $fallbackName = $this->firstName((array) ($erpFamily['names'] ?? [])) ?: (string) ($kmsSample['name'] ?? $family9);
        $fallbackBrand = (string) ($kmsSample['brand'] ?? '');
        $fallbackUnit = (string) ($kmsSample['unit'] ?? 'STK');

        $parentPayload = $this->buildParentPayload($family9, $fallbackName, $fallbackBrand, $fallbackUnit);
        $childPayload = $this->buildVariantPayload($child, $erpRows[$child], $fallbackName, $fallbackBrand, $fallbackUnit, $family9);
        $siblingPayload = $this->buildVariantPayload($sibling, $erpRows[$sibling], $fallbackName, $fallbackBrand, $fallbackUnit, $family9);

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan/live_family_probes');
        $paths = [
            'meta' => $outDir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '.json',
            'parent' => $outDir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_parent_payload.json',
            'child' => $outDir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_child_payload.json',
            'sibling' => $outDir . DIRECTORY_SEPARATOR . 'family_live_probe_' . $familyInput . '_sibling_payload.json',
        ];

        $meta = [
            'family' => $familyInput,
            'family9' => $family9,
            'mode' => 'prepared',
            'erp_article_count' => count($erpArticles),
            'kms_article_count' => count($kmsArticles),
            'missing_articles' => $missing,
            'selected_child' => $child,
            'selected_sibling' => $sibling,
            'source' => [
                'erp_family_index' => 'erp_dump/combined_family_index.json',
                'kms_products_index' => 'kms_scan/combined_products_index.json',
                'erp_prefix_matches_glob' => 'erp_dump/prefix_matches_*.json',
            ],
            'seed_files' => $paths,
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
            file_put_contents($paths['meta'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($paths['parent'], json_encode($parentPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($paths['child'], json_encode($childPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($paths['sibling'], json_encode($siblingPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->line('WROTE: ' . $paths['meta']);
            $this->line('WROTE: ' . $paths['parent']);
            $this->line('WROTE: ' . $paths['child']);
            $this->line('WROTE: ' . $paths['sibling']);
        } else {
            $this->warn('Not writing JSON (missing --write-json).');
        }

        return self::SUCCESS;
    }

    private function loadErpFamilies(): array
    {
        $path = StoragePathResolver::resolve('erp_dump/combined_family_index.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('ERP family index JSON invalid: ' . $path);
        }

        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['family'])) {
                $out[(string) $row['family']] = $row;
            }
        }

        return $out;
    }

    private function loadKmsFamilies(): array
    {
        $path = StoragePathResolver::resolve('kms_scan/combined_products_index.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['families']) || ! is_array($decoded['families'])) {
            throw new \RuntimeException('KMS products index JSON invalid: ' . $path);
        }

        return $decoded['families'];
    }

    private function loadErpRowsForFamily(string $family9): array
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

    private function buildParentPayload(string $family9, string $name, string $brand, string $unit): array
    {
        $parentCandidates = StoragePathResolver::globAll('kms_scan/parent_payload_' . $family9 . '.json');
        if (! empty($parentCandidates)) {
            $decoded = json_decode((string) file_get_contents($parentCandidates[0]), true);
            $candidate = $decoded['candidate_parent_payload'] ?? null;
            if (is_array($candidate) && isset($candidate['products']) && is_array($candidate['products']) && isset($candidate['products'][0])) {
                return $candidate;
            }
        }

        return [
            'products' => [[
                'article_number' => $family9,
                'articleNumber' => $family9,
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
                'type_number' => $family9,
                'typeNumber' => $family9,
                'type_name' => '',
                'typeName' => '',
            ]],
        ];
    }

    private function buildVariantPayload(string $article, array $erpRow, string $fallbackName, string $fallbackBrand, string $fallbackUnit, string $typeNumber): array
    {
        $ean = (string) ($erpRow['eanCodeAsText'] ?? $erpRow['eanCode'] ?? '');
        $name = trim((string) ($erpRow['description'] ?? '')) ?: $fallbackName;
        $brand = trim((string) ($erpRow['searchName'] ?? '')) ?: $fallbackBrand;
        $unit = trim((string) ($erpRow['unitCode'] ?? '')) ?: $fallbackUnit;

        $payload = [
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => $ean !== '' ? $ean : null,
            'name' => $name,
            'brand' => $brand,
            'unit' => $unit,
            'type_number' => $typeNumber,
            'typeNumber' => $typeNumber,
            'type_name' => '',
            'typeName' => '',
        ];

        if (isset($erpRow['salesPrice'])) {
            $payload['price'] = $erpRow['salesPrice'];
        }
        if (isset($erpRow['costPrice'])) {
            $payload['purchase_price'] = $erpRow['costPrice'];
        }

        $searchKeys = (string) ($erpRow['searchKeys'] ?? '');
        $parsed = $this->parseColorSize($searchKeys);
        if ($parsed['color'] !== null) {
            $payload['color'] = $parsed['color'];
        }
        if ($parsed['size'] !== null) {
            $payload['size'] = $parsed['size'];
        }

        $payload = array_filter($payload, static fn ($v) => $v !== null && $v !== '');

        return ['products' => [$payload]];
    }

    private function parseColorSize(string $searchKeys): array
    {
        $searchKeys = trim((string) preg_replace('/\s+/', ' ', $searchKeys));
        if ($searchKeys === '') {
            return ['color' => null, 'size' => null];
        }

        $parts = explode(' ', $searchKeys);
        $sizeTokens = ['XXXS','XXS','XS','S','M','L','XL','XXL','XXXL','XXXXL','XXXXXL'];
        $last = strtoupper((string) end($parts));
        if (in_array($last, $sizeTokens, true)) {
            array_pop($parts);
            $color = trim(implode(' ', $parts));
            return ['color' => $color !== '' ? $color : null, 'size' => $last];
        }

        return ['color' => null, 'size' => null];
    }

    private function firstName(array $names): ?string
    {
        if (empty($names)) {
            return null;
        }
        arsort($names);
        return (string) array_key_first($names);
    }
}
