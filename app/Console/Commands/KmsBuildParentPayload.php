<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsBuildParentPayload extends Command
{
    protected $signature = 'kms:build:parent-payload {family} {--dump-json} {--debug}';
    protected $description = 'Build a candidate KMS parent payload from ERP family and KMS scan context.';

    public function handle(): int
    {
        $familyNo = trim((string) $this->argument('family'));
        $erpFamilies = $this->loadErpFamilies();
        $kmsFamilies = $this->loadKmsFamilies();

        $family = $erpFamilies[$familyNo] ?? null;
        if (! $family) {
            $this->error('ERP family not found: ' . $familyNo);
            return self::FAILURE;
        }

        $kmsFamily = $kmsFamilies[$familyNo] ?? null;
        $seed = $kmsFamily['sample'] ?? [];
        $inferenceBasis = array_slice($kmsFamily['articles'] ?? ($family['articles'] ?? []), 0, 5);

        $name = $this->firstName($family['names'] ?? []) ?: (string) ($seed['name'] ?? '');
        $brand = (string) ($seed['brand'] ?? '');
        $unit = (string) ($seed['unit'] ?? 'STK');

        $payload = [
            'inference_basis' => array_values($inferenceBasis),
            'suspected_family_number' => $familyNo,
            'suspected_family_name' => '',
            'candidate_parent_payload' => [
                'products' => [[
                    'article_number' => $familyNo,
                    'articleNumber' => $familyNo,
                    'name' => $name,
                    'brand' => $brand,
                    'unit' => $unit,
                    'type_number' => $familyNo,
                    'typeNumber' => $familyNo,
                    'type_name' => '',
                    'typeName' => '',
                ]],
            ],
            'common_fields_across_variants' => [
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
            ],
            'variant_specific_fields_to_strip' => [
                'credit',
                'defaultColor',
                'color',
                'size',
                'hasImages',
                'imageProductId',
                'freeStockInfo',
                'deliveryDateInfo',
                'weight',
            ],
            'kms_seed_article' => $seed['articleNumber'] ?? null,
        ];

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $path = $outDir . DIRECTORY_SEPARATOR . 'parent_payload_' . $familyNo . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        throw new \RuntimeException('Could not load ERP family index. Run erp:families:combine first.');
    }

    private function loadKmsFamilies(): array
    {
        $paths = StoragePathResolver::globAll('kms_scan/combined_products_index.json');
        if (! empty($paths)) {
            $decoded = json_decode((string) file_get_contents($paths[0]), true);
            if (isset($decoded['families']) && is_array($decoded['families'])) {
                return $decoded['families'];
            }
        }

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
            sort($family['articles']);
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
