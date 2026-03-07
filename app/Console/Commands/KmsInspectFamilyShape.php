<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KmsInspectFamilyShape extends Command
{
    protected $signature = 'kms:inspect:family-shape
        {family : 9-digit family prefix or article root to inspect}
        {--limit=30 : Max rows to print}
        {--debug : Extra output}';

    protected $description = 'Inspect a KMS family prefix from combined scan data: variants, basis12 buckets, colors, sizes and likely shape.';

    public function handle(): int
    {
        $family = trim((string) $this->argument('family'));
        $family9 = substr($family, 0, 9);
        $limit = max(1, (int) $this->option('limit'));

        $files = collect(File::glob(storage_path('app/private/kms_scan/products_window_*.json')))
            ->merge(File::glob(storage_path('app/kms_scan/products_window_*.json')))
            ->unique()
            ->values()
            ->all();

        $rows = [];
        foreach ($files as $file) {
            $json = json_decode((string) @file_get_contents($file), true);
            $data = [];
            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                $data = $json['data'];
            } elseif (is_array($json) && array_is_list($json)) {
                $data = $json;
            }
            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $article = (string) ($row['articleNumber'] ?? $row['article_number'] ?? '');
                if ($article !== '' && str_starts_with($article, $family9)) {
                    $row['__source'] = $file;
                    $rows[$article] = $row;
                }
            }
        }

        ksort($rows);
        $rows = array_values($rows);

        $this->line('=== KMS FAMILY SHAPE INSPECT ===');
        $this->line('Input     : ' . $family);
        $this->line('Family9   : ' . $family9);
        $this->line('Scan hits : ' . count($rows));

        if ($rows === []) {
            $this->warn('No scan hits found for this family prefix.');
            return self::SUCCESS;
        }

        $basis12 = [];
        $colors = [];
        $sizes = [];
        $names = [];

        foreach ($rows as $row) {
            $article = (string) ($row['articleNumber'] ?? '');
            $b12 = strlen($article) >= 12 ? substr($article, 0, 12) : $article;
            $basis12[$b12] = ($basis12[$b12] ?? 0) + 1;

            $color = trim((string) ($row['color'] ?? ''));
            if ($color !== '') {
                $colors[$color] = ($colors[$color] ?? 0) + 1;
            }

            $size = trim((string) ($row['size'] ?? ''));
            if ($size !== '') {
                $sizes[$size] = ($sizes[$size] ?? 0) + 1;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        arsort($basis12);
        arsort($colors);
        arsort($sizes);
        arsort($names);

        $this->line('Top basis12 buckets');
        foreach (array_slice($basis12, 0, 10, true) as $k => $v) {
            $this->line(sprintf('%s => %d', $k, $v));
        }

        $this->line('Top colors');
        foreach (array_slice($colors, 0, 10, true) as $k => $v) {
            $this->line(sprintf('%s => %d', $k, $v));
        }

        $this->line('Top sizes');
        foreach (array_slice($sizes, 0, 15, true) as $k => $v) {
            $this->line(sprintf('%s => %d', $k, $v));
        }

        $this->line('Top names');
        foreach (array_slice($names, 0, 5, true) as $k => $v) {
            $this->line(sprintf('[%d] %s', $v, $k));
        }

        $this->line('Sample rows');
        foreach (array_slice($rows, 0, $limit) as $row) {
            $article = (string) ($row['articleNumber'] ?? '');
            $this->line(sprintf(
                '%s | %s | color=%s | size=%s | brand=%s',
                $article,
                (string) ($row['name'] ?? ''),
                (string) ($row['color'] ?? ''),
                (string) ($row['size'] ?? ''),
                (string) ($row['brand'] ?? '')
            ));
            if ($this->option('debug')) {
                $this->line('  source=' . (string) ($row['__source'] ?? ''));
            }
        }

        $singleBasis = count($basis12) === 1;
        $this->newLine();
        $this->info('Interpretation');
        if ($singleBasis) {
            $only = array_key_first($basis12);
            $this->line('This family looks like a single-color block under basis12 ' . $only . '.');
        } else {
            $this->line('This family spans multiple basis12 buckets, which strongly suggests color-level basis articles.');
        }
        $this->line('Use the dominant basis12 bucket(s) as candidate basis articles, not the raw family9 root by default.');

        return self::SUCCESS;
    }
}
