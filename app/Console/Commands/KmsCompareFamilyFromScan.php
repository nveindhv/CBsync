<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class KmsCompareFamilyFromScan extends Command
{
    protected $signature = 'kms:compare:family-from-scan
        {articles* : Variant article numbers to compare from a KMS scan dump}
        {--source= : Optional path to KMS scan JSON file}
        {--prefix-length=9 : Prefix length used as family bucket}
        {--dump-json : Write JSON result}
        {--dump-csv : Write CSV result}
        {--debug : Verbose output}';

    protected $description = 'Compare KMS family variants using a previously dumped KMS products window instead of live article lookups.';

    public function handle(): int
    {
        $source = $this->resolveSource($this->option('source'));
        if (!$source || !is_file($source)) {
            $this->error('No KMS scan JSON found. Pass --source=... or run kms:scan:products-window --dump-json first.');
            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($source), true);
        if (!is_array($rows)) {
            $this->error('Could not parse KMS scan JSON: '.$source);
            return self::FAILURE;
        }

        $wanted = collect((array) $this->argument('articles'))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values();

        $byArticle = collect($rows)->keyBy(fn ($row) => (string) ($row['articleNumber'] ?? ''));
        $found = $wanted->map(fn ($article) => $byArticle->get($article))->filter()->values();
        $missing = $wanted->filter(fn ($article) => !$byArticle->has($article))->values()->all();

        if ($found->isEmpty()) {
            $this->error('Could not find any requested articles in scan file.');
            if ($missing) {
                $this->line('Missing: '.implode(', ', $missing));
            }
            return self::FAILURE;
        }

        $prefixLength = max(1, (int) $this->option('prefix-length'));
        $familyPrefixes = $found->map(fn ($row) => substr((string) ($row['articleNumber'] ?? ''), 0, $prefixLength))
            ->unique()->values()->all();

        $common = $this->commonFields($found->all());
        $variantSpecific = $this->variantSpecificFields($found->all(), array_keys($common));

        $seed = $found->first();
        $familyNumber = substr((string) ($seed['articleNumber'] ?? ''), 0, $prefixLength);
        $commonName = (string) ($common['name'] ?? ($seed['name'] ?? ''));
        $result = [
            'source' => $source,
            'requested_articles' => $wanted->all(),
            'found_articles' => $found->pluck('articleNumber')->values()->all(),
            'missing_articles' => $missing,
            'family_prefix_length' => $prefixLength,
            'family_prefixes' => $familyPrefixes,
            'candidate_parent_payload' => [
                'products' => [[
                    'article_number' => $familyNumber,
                    'articleNumber' => $familyNumber,
                    'name' => $commonName,
                    'brand' => (string) ($common['brand'] ?? ($seed['brand'] ?? '')),
                    'unit' => (string) ($common['unit'] ?? ($seed['unit'] ?? '')),
                    'type_number' => $familyNumber,
                    'typeNumber' => $familyNumber,
                    'type_name' => $commonName,
                    'typeName' => $commonName,
                ]],
            ],
            'common_fields' => $common,
            'variant_specific_fields' => $variantSpecific,
            'compared_rows' => $found->map(function ($row) {
                return Arr::only($row, [
                    'id', 'articleNumber', 'ean', 'name', 'alias', 'price', 'purchasePrice',
                    'active', 'unit', 'brand', 'color', 'size', 'supplierId', 'supplierName',
                    'createDate', 'modifyDate',
                ]);
            })->all(),
        ];

        $this->info('=== FAMILY COMPARISON FROM SCAN ===');
        $this->line('Source   : '.$source);
        $this->line('Found    : '.count($result['found_articles']));
        $this->line('Missing  : '.count($missing));
        $this->line('Prefixes : '.implode(', ', $familyPrefixes));
        if ($missing) {
            $this->warn('Missing articles: '.implode(', ', $missing));
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $stamp = now()->format('Ymd_His');
        $baseDir = 'private/kms_scan';

        if ($this->option('dump-json')) {
            $jsonPath = $baseDir.'/family_compare_from_scan_'.$stamp.'.json';
            Storage::put($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: '.storage_path('app/'.$jsonPath));
        }

        if ($this->option('dump-csv')) {
            $csvPath = $baseDir.'/family_compare_from_scan_'.$stamp.'.csv';
            $csv = $this->toCsv($result['compared_rows']);
            Storage::put($csvPath, $csv);
            $this->line('CSV : '.storage_path('app/'.$csvPath));
        }

        return self::SUCCESS;
    }

    private function resolveSource(?string $arg): ?string
    {
        if ($arg) {
            return $arg;
        }

        $candidates = [
            storage_path('app/private/kms_scan'),
            storage_path('app/kms_scan'),
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir.'/products_window_*.json') ?: [];
            rsort($files);
            if (!empty($files)) {
                return $files[0];
            }
        }

        return null;
    }

    private function commonFields(array $rows): array
    {
        $first = $rows[0] ?? [];
        $common = [];

        foreach ($first as $key => $value) {
            $same = true;
            foreach ($rows as $row) {
                if (($row[$key] ?? null) != $value) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $common[$key] = $value;
            }
        }

        return $common;
    }

    private function variantSpecificFields(array $rows, array $commonKeys): array
    {
        $keys = collect($rows)->flatMap(fn ($row) => array_keys($row))->unique()->values();
        return $keys->reject(fn ($key) => in_array($key, $commonKeys, true))->values()->all();
    }

    private function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return "articleNumber\n";
        }

        $headers = collect($rows)->flatMap(fn ($row) => array_keys($row))->unique()->values()->all();
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? null;
                $line[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }
}
