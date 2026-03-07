<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsInspectLayersFixed extends Command
{
    protected $signature = 'kms:inspect:layers:fixed
        {source? : Optional absolute path, relative storage path, or file name}
        {--dump-json : Save JSON report}
        {--dump-csv : Save CSV report}
        {--debug : Show extra details}';

    protected $description = 'Inspect a KMS scan JSON with robust source-path resolution, including storage/app/private/kms_scan.';

    public function handle(): int
    {
        $source = $this->resolveSource($this->argument('source'));
        if (!$source) {
            $this->error('No KMS scan JSON found. Checked storage/app/private/kms_scan, storage/app/kms_scan, and the exact path you passed.');
            return self::FAILURE;
        }

        $raw = @file_get_contents($source);
        $rows = json_decode((string) $raw, true);
        if (!is_array($rows)) {
            $this->error('Could not decode JSON: ' . $source);
            return self::FAILURE;
        }

        $this->info('Source : ' . $source);
        $this->line('Rows   : ' . count($rows));
        $this->line('Tip    : this fixed command mainly solves the private/public storage path mismatch.');

        $prefixBuckets = [];
        $descBuckets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $article = trim((string) ($row['articleNumber'] ?? $row['article_number'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($article !== '') {
                $prefix = substr($article, 0, min(9, strlen($article)));
                $prefixBuckets[$prefix] = ($prefixBuckets[$prefix] ?? 0) + 1;
            }
            if ($name !== '') {
                $key = mb_strtolower($name);
                $descBuckets[$key] = ($descBuckets[$key] ?? 0) + 1;
            }
        }

        arsort($prefixBuckets);
        arsort($descBuckets);

        $summary = [
            'source' => $source,
            'rows' => count($rows),
            'top_family_prefixes' => array_slice($prefixBuckets, 0, 25, true),
            'top_repeated_names' => array_slice($descBuckets, 0, 25, true),
        ];

        $this->newLine();
        $this->info('Top family prefixes');
        foreach ($summary['top_family_prefixes'] as $prefix => $count) {
            $this->line($prefix . ' => ' . $count);
        }

        $this->newLine();
        $this->info('Top repeated names');
        foreach ($summary['top_repeated_names'] as $name => $count) {
            $this->line('[' . $count . '] ' . $name);
        }

        $stamp = now()->format('Ymd_His');
        $dir = 'private/kms_scan';
        Storage::makeDirectory($dir);

        if ($this->option('dump-json')) {
            $jsonPath = $dir . '/layer_analysis_fixed_' . $stamp . '.json';
            Storage::put($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('JSON: ' . storage_path('app/' . $jsonPath));
        }

        if ($this->option('dump-csv')) {
            $csvPath = $dir . '/layer_analysis_fixed_' . $stamp . '.csv';
            $lines = ["prefix;count"];
            foreach ($summary['top_family_prefixes'] as $prefix => $count) {
                $lines[] = $prefix . ';' . $count;
            }
            Storage::put($csvPath, "\xEF\xBB\xBF" . implode("\n", $lines));
            $this->line('CSV : ' . storage_path('app/' . $csvPath));
        }

        return self::SUCCESS;
    }

    private function resolveSource(?string $argument): ?string
    {
        $candidates = [];

        $argument = is_string($argument) ? trim($argument) : '';
        if ($argument !== '') {
            $candidates[] = $argument;
            $candidates[] = storage_path('app/' . ltrim(str_replace('\\', '/', $argument), '/'));
            $candidates[] = storage_path('app/private/kms_scan/' . basename($argument));
            $candidates[] = storage_path('app/kms_scan/' . basename($argument));
        }

        foreach (glob(storage_path('app/private/kms_scan/products_window_*.json')) ?: [] as $file) {
            $candidates[] = $file;
        }
        foreach (glob(storage_path('app/kms_scan/products_window_*.json')) ?: [] as $file) {
            $candidates[] = $file;
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        rsort($candidates);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
