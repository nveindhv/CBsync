<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ErpInspectBaseCandidates extends Command
{
    protected $signature = 'erp:inspect:base-candidates
        {--json= : Path to ERP JSON file. Default: newest storage/app/erp_dump/prefix_matches_*.json}
        {--prefix= : Filter productCode/externalProductCode by prefix}
        {--description= : Case-insensitive substring filter on description}
        {--limit=40 : Max suspicious rows to print}
        {--show-rows=12 : Max rows per repeated-description block}
        {--dump-json : Write result snapshot to storage/app/erp_dump}
        {--debug : Extra output}';

    protected $description = 'Inspect ERP product dumps for abnormal / base-product-like records and family patterns.';

    public function handle(): int
    {
        $path = $this->resolveJsonPath();
        if (!$path || !is_file($path)) {
            $this->error('JSON not found. Use --json=... or first create a dump with erp:find:products.');
            $this->line('Expected for example: storage/app/erp_dump/prefix_matches_*.json');
            return self::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            $this->error('Invalid JSON: ' . $path);
            return self::FAILURE;
        }

        $rows = collect($raw)
            ->filter(fn ($r) => is_array($r))
            ->map(fn ($r) => $this->normalizeRow($r));

        $rows = $this->applyFilters($rows);

        $deduped = $rows->unique(fn ($r) => implode('|', [
            $r['productCode'],
            $r['eanCodeAsText'],
            $r['unitCode'],
            $r['description'],
        ]))->values();

        if ($deduped->isEmpty()) {
            $this->warn('No rows left after filters.');
            return self::SUCCESS;
        }

        $withScores = $deduped->map(function (array $row) use ($deduped) {
            $row['suspicion'] = $this->scoreRow($row, $deduped);
            $row['signals'] = $this->signalsForRow($row, $deduped);
            return $row;
        })->sortByDesc('suspicion')->values();

        $this->line('Source JSON : ' . $path);
        $this->line('Rows loaded  : ' . count($raw));
        $this->line('Rows filtered: ' . $rows->count());
        $this->line('Rows deduped : ' . $deduped->count());
        $this->newLine();

        $this->comment('Top suspicious / base-like candidates');
        $limit = max(1, (int) $this->option('limit'));
        foreach ($withScores->take($limit) as $row) {
            $this->line(sprintf(
                '[%02d] %s | ean=%s | desc=%s | signals=%s',
                (int) $row['suspicion'],
                $row['productCode'] ?: '[no productCode]',
                $row['eanCodeAsText'] ?: '-',
                $row['description'] ?: '-',
                implode(', ', $row['signals'])
            ));
        }

        $this->newLine();
        $this->comment('Repeated descriptions (possible families)');
        $this->printDescriptionGroups($deduped);

        $this->newLine();
        $this->comment('Prefix density');
        $this->printPrefixDensity($deduped);

        if ($this->option('dump-json')) {
            $this->dumpSnapshot($path, $withScores, $deduped);
        }

        return self::SUCCESS;
    }

    private function resolveJsonPath(): ?string
    {
        $explicit = (string) ($this->option('json') ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $dir = storage_path('app/erp_dump');
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'prefix_matches_*.json') ?: [];
        if (empty($files)) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        }
        if (empty($files)) {
            return null;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0] ?? null;
    }

    private function normalizeRow(array $r): array
    {
        $productCode = trim((string) Arr::get($r, 'productCode', ''));
        $externalProductCode = trim((string) Arr::get($r, 'externalProductCode', ''));
        $description = trim((string) Arr::get($r, 'description', ''));
        $eanText = trim((string) Arr::get($r, 'eanCodeAsText', (string) Arr::get($r, 'eanCode', '')));

        return [
            'productCode' => $productCode,
            'externalProductCode' => $externalProductCode,
            'description' => $description,
            'eanCodeAsText' => $eanText,
            'unitCode' => trim((string) Arr::get($r, 'unitCode', '')),
            'searchName' => trim((string) Arr::get($r, 'searchName', '')),
            'searchKeys' => trim((string) Arr::get($r, 'searchKeys', '')),
            'inactive' => (bool) Arr::get($r, 'inactive', false),
            'blocked' => (bool) Arr::get($r, 'blocked', false),
            'discontinued' => (bool) Arr::get($r, 'discontinued', false),
            'costPrice' => Arr::get($r, 'costPrice'),
            'raw' => $r,
        ];
    }

    private function applyFilters(Collection $rows): Collection
    {
        $prefix = trim((string) ($this->option('prefix') ?? ''));
        if ($prefix !== '') {
            $rows = $rows->filter(function (array $r) use ($prefix) {
                return str_starts_with($r['productCode'], $prefix)
                    || str_starts_with($r['externalProductCode'], $prefix);
            })->values();
        }

        $desc = trim((string) ($this->option('description') ?? ''));
        if ($desc !== '') {
            $needle = mb_strtolower($desc);
            $rows = $rows->filter(function (array $r) use ($needle) {
                return str_contains(mb_strtolower($r['description']), $needle);
            })->values();
        }

        return $rows;
    }

    private function scoreRow(array $row, Collection $all): int
    {
        $score = 0;
        $code = $row['productCode'];

        if ($code === '') {
            return -99;
        }

        if (preg_match('/[A-Z]/i', $code)) {
            $score += 8;
        }
        if (preg_match('/0{6,}$/', $code)) {
            $score += 7;
        }
        if (preg_match('/EEN0+$/i', $code)) {
            $score += 10;
        }
        if ($row['externalProductCode'] === '' || $row['externalProductCode'] !== $row['productCode']) {
            $score += 1;
        }

        $sameDescription = $all->filter(fn ($r) => $r['description'] === $row['description'])->count();
        if ($sameDescription >= 8) {
            $score += 2;
        }

        foreach ([5, 8, 9, 10, 11] as $len) {
            if (strlen($code) >= $len) {
                $prefix = substr($code, 0, $len);
                $familyCount = $all->filter(fn ($r) => str_starts_with($r['productCode'], $prefix))->count();
                if ($familyCount >= 8) {
                    $score += 1;
                }
            }
        }

        return $score;
    }

    private function signalsForRow(array $row, Collection $all): array
    {
        $signals = [];
        $code = $row['productCode'];

        if (preg_match('/[A-Z]/i', $code)) $signals[] = 'letters_in_code';
        if (preg_match('/0{6,}$/', $code)) $signals[] = 'many_trailing_zeroes';
        if (preg_match('/EEN0+$/i', $code)) $signals[] = 'een_zero_pattern';
        if ($row['externalProductCode'] === '') $signals[] = 'empty_externalProductCode';
        if ($row['externalProductCode'] !== '' && $row['externalProductCode'] === $row['productCode']) $signals[] = 'external_equals_product';

        $sameDescription = $all->filter(fn ($r) => $r['description'] === $row['description'])->count();
        if ($sameDescription >= 8) $signals[] = 'description_repeats_' . $sameDescription . 'x';

        foreach ([5, 8, 9, 10, 11] as $len) {
            if (strlen($code) >= $len) {
                $prefix = substr($code, 0, $len);
                $familyCount = $all->filter(fn ($r) => str_starts_with($r['productCode'], $prefix))->count();
                if ($familyCount >= 8) {
                    $signals[] = 'prefix' . $len . '_family_' . $familyCount . 'x';
                    break;
                }
            }
        }

        return $signals ?: ['none'];
    }

    private function printDescriptionGroups(Collection $rows): void
    {
        $maxRows = max(1, (int) $this->option('show-rows'));

        $groups = $rows->groupBy('description')
            ->map(fn ($g, $desc) => [
                'description' => $desc,
                'count' => $g->count(),
                'codes' => $g->pluck('productCode')->filter()->unique()->values()->all(),
            ])
            ->sortByDesc('count')
            ->values();

        foreach ($groups->take(10) as $group) {
            if ($group['count'] < 3) {
                continue;
            }
            $codes = array_slice($group['codes'], 0, $maxRows);
            $this->line(sprintf(
                '[%d] %s',
                $group['count'],
                $group['description'] ?: '[no description]'
            ));
            $this->line('    codes: ' . implode(', ', $codes));
        }
    }

    private function printPrefixDensity(Collection $rows): void
    {
        foreach ([5, 8, 9, 10, 11] as $len) {
            $top = $rows->filter(fn ($r) => strlen($r['productCode']) >= $len)
                ->groupBy(fn ($r) => substr($r['productCode'], 0, $len))
                ->map(fn ($g, $prefix) => ['prefix' => $prefix, 'count' => $g->count()])
                ->sortByDesc('count')
                ->take(8)
                ->values();

            $this->line('len=' . $len . ' => ' . $top->map(fn ($x) => $x['prefix'] . ':' . $x['count'])->implode(' | '));
        }
    }

    private function dumpSnapshot(string $sourcePath, Collection $withScores, Collection $deduped): void
    {
        $dir = storage_path('app/erp_dump');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'base_candidates_' . date('Ymd_His') . '.json';
        $payload = [
            'source' => $sourcePath,
            'generated_at' => date('c'),
            'filters' => [
                'prefix' => $this->option('prefix'),
                'description' => $this->option('description'),
            ],
            'rows_deduped' => $deduped->count(),
            'top_suspicious' => $withScores->take(max(1, (int) $this->option('limit')))->values()->all(),
        ];

        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Dumped JSON: ' . $file);
    }
}
