<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsMapErpFamilies extends Command
{
    protected $signature = 'kms:map:erp-families {--family=} {--dump-json} {--debug}';
    protected $description = 'Map ERP family buckets against KMS scan data.';

    public function handle(): int
    {
        $erpFamilies = $this->loadErpFamilies();
        $kmsFamilies = $this->loadKmsFamilies();
        $filter = trim((string) $this->option('family'));

        $rows = [];
        foreach ($erpFamilies as $familyNo => $family) {
            if ($filter !== '' && $filter !== $familyNo) {
                continue;
            }

            $kms = $kmsFamilies[$familyNo] ?? null;
            $kmsCount = (int) ($kms['article_count'] ?? 0);
            $erpCount = (int) ($family['article_count'] ?? 0);

            $rows[] = [
                'family' => $familyNo,
                'erp_variant_count' => $erpCount,
                'kms_variant_count' => $kmsCount,
                'kms_match_mode' => $kmsCount === 0 ? 'none' : ($kmsCount >= $erpCount ? 'full_or_over' : 'partial'),
                'suspected_parent_mode' => $kmsCount > 0 ? 'variant_anchor_exists' : 'short_parent_missing',
                'recommended_action' => $kmsCount > 0 ? 'build_parent_from_known_data' : 'bootstrap_parent',
                'top_name' => $this->firstName($family['names'] ?? []),
                'kms_sample_article' => $kms['sample']['articleNumber'] ?? null,
            ];
        }

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $path = $outDir . DIRECTORY_SEPARATOR . ($filter !== '' ? ('erp_kms_family_map_' . $filter . '.json') : 'erp_kms_family_map.json');
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        $this->line('JSON: ' . $path);

        return self::SUCCESS;
    }

    private function loadErpFamilies(): array
    {
        $paths = StoragePathResolver::globAll('erp_dump/combined_family_index.json');
        if (! empty($paths)) {
            $decoded = json_decode((string) file_get_contents($paths[0]), true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $row) {
                    if (isset($row['family'])) {
                        $out[(string) $row['family']] = $row;
                    }
                }
                return $out;
            }
        }

        $files = StoragePathResolver::globAll('erp_dump/prefix_matches_*.json');
        $families = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $productCode = trim((string) ($row['productCode'] ?? ''));
                if ($productCode === '') {
                    continue;
                }
                $family = substr($productCode, 0, 9);
                $families[$family] ??= ['family' => $family, 'article_count' => 0, 'names' => []];
                $families[$family]['article_count']++;
                $name = trim((string) ($row['description'] ?? $row['name'] ?? ''));
                if ($name !== '') {
                    $families[$family]['names'][$name] = ($families[$family]['names'][$name] ?? 0) + 1;
                }
            }
        }
        return $families;
    }

    private function loadKmsFamilies(): array
    {
        $paths = StoragePathResolver::globAll('kms_scan/combined_products_index.json');
        if (! empty($paths)) {
            $decoded = json_decode((string) file_get_contents($paths[0]), true);
            if (isset($decoded['families']) && is_array($decoded['families'])) {
                return $decoded['families'];
            }
            if (isset($decoded['by_prefix']) && is_array($decoded['by_prefix'])) {
                $out = [];
                foreach ($decoded['by_prefix'] as $family => $articles) {
                    $out[(string) $family] = [
                        'family' => (string) $family,
                        'article_count' => is_array($articles) ? count($articles) : 0,
                        'articles' => is_array($articles) ? $articles : [],
                        'sample' => ['articleNumber' => is_array($articles) && ! empty($articles) ? $articles[0] : null],
                    ];
                }
                return $out;
            }
        }

        return $this->buildKmsFamiliesFromRawScans();
    }

    private function buildKmsFamiliesFromRawScans(): array
    {
        $files = StoragePathResolver::globAll('kms_scan/products_window_*.json');
        $families = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $article = trim((string) ($row['articleNumber'] ?? ''));
                if ($article === '') {
                    continue;
                }
                $family = substr($article, 0, 9);
                if (! isset($families[$family])) {
                    $families[$family] = [
                        'family' => $family,
                        'articles' => [],
                        'sample' => [
                            'articleNumber' => $article,
                            'name' => (string) ($row['name'] ?? ''),
                            'brand' => (string) ($row['brand'] ?? ''),
                            'unit' => (string) ($row['unit'] ?? 'STK'),
                        ],
                    ];
                }
                $families[$family]['articles'][$article] = $article;
            }
        }
        foreach ($families as &$family) {
            $family['articles'] = array_values($family['articles']);
            $family['article_count'] = count($family['articles']);
        }
        unset($family);
        return $families;
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
