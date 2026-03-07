<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsInspectLayers extends Command
{
    protected $signature = 'kms:inspect:layers
        {source? : JSON file under storage/app or absolute path}
        {--family-prefix-len=9 : Prefix length used as family bucket}
        {--dump-json : Dump analysis JSON}
        {--debug : Print extra details}';

    protected $description = 'Inspect a KMS dump for likely variant/family/head-product layers.';

    public function handle(): int
    {
        $source = $this->resolveSource((string) ($this->argument('source') ?? ''));
        if ($source === null) {
            $this->error('No KMS scan JSON found. Run kms:scan:products-window --dump-json first or pass a source file.');
            return self::FAILURE;
        }

        $familyPrefixLen = max(1, (int) $this->option('family-prefix-len'));
        $dumpJson = (bool) $this->option('dump-json');
        $debug = (bool) $this->option('debug');

        $raw = @file_get_contents($source);
        $rows = json_decode($raw ?: '[]', true);
        if (!is_array($rows)) {
            $this->error('Invalid JSON source: ' . $source);
            return self::FAILURE;
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $descriptionCounts = [];
        $familyCounts = [];

        foreach ($rows as $row) {
            $description = $this->text($row['name'] ?? $row['description'] ?? '');
            if ($description !== '') {
                $key = mb_strtolower($description);
                $descriptionCounts[$key] = ($descriptionCounts[$key] ?? 0) + 1;
            }
            $article = $this->article($row);
            if ($article !== '') {
                $family = substr($article, 0, min($familyPrefixLen, strlen($article)));
                $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            }
        }

        $analysis = [];
        foreach ($rows as $row) {
            $article = $this->article($row);
            $name = $this->text($row['name'] ?? $row['description'] ?? '');
            $brand = $this->text($row['brand'] ?? '');
            $unit = $this->text($row['unit'] ?? '');
            $ean = $this->text($row['ean'] ?? $row['eanCode'] ?? '');
            $typeNumber = $this->text($row['type_number'] ?? $row['typeNumber'] ?? '');
            $typeName = $this->text($row['type_name'] ?? $row['typeName'] ?? '');
            $descriptionKey = mb_strtolower($name);
            $family = $article !== '' ? substr($article, 0, min($familyPrefixLen, strlen($article))) : '';

            $signals = [];
            $score = 0;

            if ($article !== '' && strlen($article) <= $familyPrefixLen + 1) {
                $signals[] = 'short_article';
                $score += 3;
            }
            if ($typeNumber !== '' || $typeName !== '') {
                $signals[] = 'has_type_fields';
                $score += 2;
            }
            if ($descriptionKey !== '' && ($descriptionCounts[$descriptionKey] ?? 0) >= 3) {
                $signals[] = 'description_repeats_' . $descriptionCounts[$descriptionKey] . 'x';
                $score += 1;
            }
            if ($family !== '' && ($familyCounts[$family] ?? 0) >= 3) {
                $signals[] = 'family_prefix_' . $familyCounts[$family] . 'x';
                $score += 1;
            }
            if ($this->looksHeadLike($row)) {
                $signals[] = 'head_like_fields';
                $score += 2;
            }
            if ($this->looksVariantLike($row)) {
                $signals[] = 'variant_like_fields';
            }

            $layer = 'variant';
            if ($score >= 5) {
                $layer = 'possible_head_or_parent';
            } elseif ($score >= 3) {
                $layer = 'family_or_matrix_parent';
            }

            $analysis[] = [
                'article' => $article,
                'ean' => $ean,
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
                'type_number' => $typeNumber,
                'type_name' => $typeName,
                'family_prefix' => $family,
                'layer_guess' => $layer,
                'score' => $score,
                'signals' => implode('|', $signals),
            ];
        }

        usort($analysis, fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: strcmp((string) $a['article'], (string) $b['article']));

        $this->info('Source : ' . $source);
        $this->info('Rows   : ' . count($analysis));
        $this->newLine();
        $this->line('Top parent/family candidates');
        foreach (array_slice($analysis, 0, 20) as $row) {
            $this->line(sprintf(
                '[%02d] %s | %s | layer=%s | signals=%s',
                (int) $row['score'],
                $row['article'] !== '' ? $row['article'] : '(empty)',
                $this->short($row['name']),
                $row['layer_guess'],
                $row['signals']
            ));
        }

        if ($debug) {
            $this->newLine();
            $this->line('Family buckets');
            arsort($familyCounts);
            foreach (array_slice($familyCounts, 0, 20, true) as $family => $count) {
                $this->line($family . ' => ' . $count);
            }
        }

        $dir = 'kms_scan';
        Storage::makeDirectory($dir);
        $stamp = now()->format('Ymd_His');
        $base = 'layer_analysis_' . $stamp;
        Storage::put($dir . '/' . $base . '.csv', $this->toCsv($analysis));
        $this->line('CSV : ' . storage_path('app/' . $dir . '/' . $base . '.csv'));

        if ($dumpJson) {
            Storage::put($dir . '/' . $base . '.json', json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: ' . storage_path('app/' . $dir . '/' . $base . '.json'));
        }

        return self::SUCCESS;
    }

    private function resolveSource(string $source): ?string
    {
        $source = trim($source);
        if ($source !== '') {
            if (is_file($source)) {
                return $source;
            }
            $storagePath = storage_path('app/' . ltrim(str_replace('storage/app/', '', $source), '/'));
            return is_file($storagePath) ? $storagePath : null;
        }

        $files = glob(storage_path('app/kms_scan/products_window_*.json')) ?: [];
        rsort($files);
        return $files[0] ?? null;
    }

    /** @param array<string,mixed> $row */
    private function article(array $row): string
    {
        return $this->text($row['article_number'] ?? $row['articleNumber'] ?? $row['article'] ?? '');
    }

    /** @param array<string,mixed> $row */
    private function looksHeadLike(array $row): bool
    {
        $name = $this->text($row['name'] ?? $row['description'] ?? '');
        $size = $this->text($row['size'] ?? '');
        $color = $this->text($row['color'] ?? '');
        return $name !== '' && $size === '' && $color === '';
    }

    /** @param array<string,mixed> $row */
    private function looksVariantLike(array $row): bool
    {
        $size = $this->text($row['size'] ?? '');
        $color = $this->text($row['color'] ?? '');
        return $size !== '' || $color !== '';
    }

    private function text(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return '';
    }

    private function short(string $value, int $max = 70): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function toCsv(array $rows): string
    {
        $headers = [];
        foreach ($rows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = (string) ($row[$header] ?? '');
            }
            fputcsv($stream, $line);
        }
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}
