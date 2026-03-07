<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KmsProbeErpWindowSmart extends Command
{
    protected $signature = 'kms:probe:erp-window-smart
        {--offset-from=88000 : First ERP offset}
        {--offset-to=100000 : Last ERP offset (exclusive)}
        {--page-size=50 : ERP page size}
        {--select=* : ERP select clause}
        {--only-classification= : Optional productClassification filter, e.g. WEB}
        {--include-inactive : Include inactive/blocked/discontinued ERP rows}
        {--insecure : Disable ERP SSL verification}
        {--timeout=120 : ERP request timeout in seconds}
        {--connect-timeout=20 : ERP connect timeout in seconds}
        {--retries=1 : ERP retry count}
        {--delay-ms=250 : Retry delay in ms}
        {--base-url= : Override ERP base URL}
        {--username= : Override ERP username}
        {--password= : Override ERP password}
        {--save-json : Also write JSON output}
        {--debug : Verbose diagnostics}';

    protected $description = 'Smart ERP 88k-100k triage scan: classify rows into updateable, missing, abnormal base/family candidates, and family summaries.';

    public function handle(): int
    {
        $offsetFrom = max(0, (int) $this->option('offset-from'));
        $offsetTo = max($offsetFrom, (int) $this->option('offset-to'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $select = $this->normalizeScalarOption($this->option('select'), '*');
        $onlyClassification = trim($this->normalizeScalarOption($this->option('only-classification'), ''));
        $includeInactive = (bool) $this->option('include-inactive');
        $verifySsl = !(bool) $this->option('insecure');
        $timeout = max(5, (int) $this->option('timeout'));
        $connectTimeout = max(2, (int) $this->option('connect-timeout'));
        $retries = max(0, (int) $this->option('retries'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $saveJson = (bool) $this->option('save-json');
        $debug = (bool) $this->option('debug');

        $baseUrl = rtrim($this->normalizeScalarOption($this->option('base-url'), $this->guessErpBaseUrl()), '/');
        $username = $this->normalizeScalarOption($this->option('username'), $this->guessErpUsername());
        $password = $this->normalizeScalarOption($this->option('password'), $this->guessErpPassword());

        if ($baseUrl === '') {
            $this->error('ERP base URL not found. Pass --base-url or configure your ERP URL in config/env.');
            return self::FAILURE;
        }

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $rows = [];
        $summary = [
            'pages' => 0,
            'rows_seen' => 0,
            'rows_with_article' => 0,
            'existing_kms' => 0,
            'missing_kms' => 0,
            'updateable_existing' => 0,
            'missing_normal_variant' => 0,
            'abnormal_base_candidates' => 0,
            'classification_filtered_out' => 0,
            'inactive_filtered_out' => 0,
            'missing_article_filtered_out' => 0,
            'erp_errors' => 0,
        ];

        $descriptionCounts = [];
        $prefixCounts = [];

        for ($offset = $offsetFrom; $offset < $offsetTo; $offset += $pageSize) {
            $page = $this->fetchErpPage(
                $baseUrl,
                $username,
                $password,
                $offset,
                $pageSize,
                $select,
                $verifySsl,
                $timeout,
                $connectTimeout,
                $retries,
                $delayMs,
                $debug
            );

            if ($page === null) {
                $summary['erp_errors']++;
                continue;
            }

            $summary['pages']++;
            foreach ($page as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $summary['rows_seen']++;

                $article = $this->extractArticle($row);
                $ean = $this->extractEan($row);
                $description = trim($this->normalizeScalar($this->pick($row, ['description', 'name', 'productDescription'])));
                $unit = trim($this->normalizeScalar($this->pick($row, ['unitCode', 'unit'])));
                $brand = trim($this->normalizeScalar($this->pick($row, ['searchName', 'brand', 'brandName'])));
                $color = $this->guessColor($row, $description);
                $size = $this->guessSize($row, $description);
                $purchasePrice = $this->normalizeNumber($this->pick($row, ['costPrice', 'purchasePrice', 'purchase_price']));
                $classification = $this->extractClassification($row);
                $externalProductCode = trim($this->normalizeScalar($this->pick($row, ['externalProductCode'])));
                $inactive = $this->isInactive($row);

                if ($article === '') {
                    $summary['missing_article_filtered_out']++;
                    continue;
                }

                if (!$includeInactive && $inactive) {
                    $summary['inactive_filtered_out']++;
                    continue;
                }

                if ($onlyClassification !== '' && strcasecmp($onlyClassification, $classification) !== 0) {
                    $summary['classification_filtered_out']++;
                    continue;
                }

                $summary['rows_with_article']++;

                $descKey = mb_strtolower($description);
                if ($descKey !== '') {
                    $descriptionCounts[$descKey] = ($descriptionCounts[$descKey] ?? 0) + 1;
                }

                foreach ([5, 8, 9, 10, 11] as $len) {
                    $prefix = substr($article, 0, min($len, strlen($article)));
                    if ($prefix === '') {
                        continue;
                    }
                    $prefixCounts[$len][$prefix] = ($prefixCounts[$len][$prefix] ?? 0) + 1;
                }

                $existsInKms = $this->kmsExists($kms, $article, $ean);
                if ($existsInKms) {
                    $summary['existing_kms']++;
                } else {
                    $summary['missing_kms']++;
                }

                $signals = $this->abnormalSignals($row, $article, $description, $externalProductCode, $classification);
                $familyGuess = $this->guessFamilyKey($article, $description);
                $baseScore = count($signals);

                $path = 'missing_normal_variant';
                if ($existsInKms) {
                    $path = 'update_existing';
                    $summary['updateable_existing']++;
                } elseif ($baseScore > 0) {
                    $path = 'abnormal_base_candidate';
                    $summary['abnormal_base_candidates']++;
                } else {
                    $summary['missing_normal_variant']++;
                }

                $rows[] = [
                    'article' => $article,
                    'ean' => $ean,
                    'description' => $description,
                    'unit' => $unit,
                    'brand' => $brand,
                    'color_guess' => $color,
                    'size_guess' => $size,
                    'purchase_price' => $purchasePrice,
                    'classification' => $classification,
                    'external_product_code' => $externalProductCode,
                    'inactive' => $inactive ? '1' : '0',
                    'kms_exists' => $existsInKms ? '1' : '0',
                    'path' => $path,
                    'family_guess' => $familyGuess,
                    'base_candidate_score' => $baseScore,
                    'signals' => implode('|', $signals),
                    'raw_id' => trim($this->normalizeScalar($this->pick($row, ['id']))),
                ];
            }
        }

        foreach ($rows as &$row) {
            $descKey = mb_strtolower((string) $row['description']);
            $repeatCount = $descKey !== '' ? ($descriptionCounts[$descKey] ?? 0) : 0;
            $row['description_repeat_count'] = $repeatCount;
            if ($repeatCount >= 3) {
                $signals = $row['signals'] !== '' ? explode('|', $row['signals']) : [];
                $signals[] = 'description_repeats_' . $repeatCount . 'x';
                $row['signals'] = implode('|', array_values(array_unique(array_filter($signals))));
                $row['base_candidate_score'] = (int) $row['base_candidate_score'] + 1;
                if ($row['kms_exists'] !== '1' && $row['path'] === 'missing_normal_variant') {
                    $row['path'] = 'abnormal_base_candidate';
                    $summary['missing_normal_variant'] = max(0, $summary['missing_normal_variant'] - 1);
                    $summary['abnormal_base_candidates']++;
                }
            }
        }
        unset($row);

        $familySummary = $this->buildFamilySummary($rows);

        $stamp = now()->format('Ymd_His');
        $dir = 'kms_probe_window_smart';
        Storage::makeDirectory($dir);

        $baseName = sprintf('smart_%d_%d_%s', $offsetFrom, $offsetTo, $stamp);
        $allCsv = $dir . '/' . $baseName . '_all.csv';
        $missingCsv = $dir . '/' . $baseName . '_missing.csv';
        $abnormalCsv = $dir . '/' . $baseName . '_abnormal.csv';
        $familyCsv = $dir . '/' . $baseName . '_family_summary.csv';

        $this->writeCsv($allCsv, $rows);
        $this->writeCsv($missingCsv, array_values(array_filter($rows, fn ($r) => $r['kms_exists'] !== '1')));
        $this->writeCsv($abnormalCsv, array_values(array_filter($rows, fn ($r) => $r['path'] === 'abnormal_base_candidate')));
        $this->writeCsv($familyCsv, $familySummary);

        if ($saveJson) {
            Storage::put($dir . '/' . $baseName . '_all.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Storage::put($dir . '/' . $baseName . '_family_summary.json', json_encode($familySummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->info('ALL     : ' . storage_path('app/' . $allCsv));
        $this->info('MISSING : ' . storage_path('app/' . $missingCsv));
        $this->info('ABNORMAL: ' . storage_path('app/' . $abnormalCsv));
        $this->info('FAMILY  : ' . storage_path('app/' . $familyCsv));
        $this->line('Summary: ' . json_encode($summary, JSON_UNESCAPED_SLASHES));
        $this->line('Interpretation: existing KMS => update path; missing + normal => probable create candidate; missing + abnormal => inspect as base/family candidate first.');
        $this->line('ERP SSL verify: ' . ($verifySsl ? 'ON' : 'OFF'));

        return self::SUCCESS;
    }

    private function fetchErpPage(
        string $baseUrl,
        string $username,
        string $password,
        int $offset,
        int $limit,
        string $select,
        bool $verifySsl,
        int $timeout,
        int $connectTimeout,
        int $retries,
        int $delayMs,
        bool $debug
    ): ?array {
        $query = ['offset' => $offset, 'limit' => $limit];
        if ($select !== '') {
            $query['select'] = $select;
        }

        $request = Http::withBasicAuth($username, $password)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->retry($retries, $delayMs, throw: false)
            ->withOptions(['verify' => $verifySsl]);

        $url = $baseUrl . '/products';

        try {
            $response = $request->get($url, $query);
            if (!$response->successful()) {
                $this->warn(sprintf('[ERP_GET_FAILED] offset=%d status=%d verify_ssl=%s', $offset, $response->status(), $verifySsl ? 'true' : 'false'));
                if ($debug) {
                    $this->line('URL: ' . $response->effectiveUri());
                    $this->line('BODY: ' . mb_substr($response->body(), 0, 1000));
                }
                return null;
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [];
            }

            if ($debug) {
                $this->line(sprintf('[ERP_PAGE_OK] offset=%d rows=%d', $offset, count($json)));
            }

            return $json;
        } catch (Throwable $e) {
            $this->warn(sprintf('[ERP_GET_EXCEPTION] offset=%d error=%s', $offset, $e->getMessage()));
            return null;
        }
    }

    private function kmsExists(KmsClient $kms, string $article, string $ean): bool
    {
        $items = $this->normalizeProductsResponse($kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ]));

        foreach ($items as $item) {
            if ((string) Arr::get($item, 'articleNumber') === $article) {
                return true;
            }
        }

        if ($ean !== '') {
            $items = $this->normalizeProductsResponse($kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ]));
            foreach ($items as $item) {
                if ((string) Arr::get($item, 'ean') === $ean) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeProductsResponse($res): array
    {
        if (!is_array($res) || empty($res)) {
            return [];
        }
        $keys = array_keys($res);
        $isNumericList = ($keys === range(0, count($keys) - 1));
        $items = $isNumericList ? $res : array_values($res);
        return array_values(array_filter($items, fn ($x) => is_array($x)));
    }

    private function extractArticle(array $row): string
    {
        $candidates = [
            $this->pick($row, ['productCode', 'articleNumber', 'article_number']),
            $this->pick($row, ['externalProductCode']),
        ];

        foreach ($candidates as $value) {
            $scalar = trim($this->normalizeScalar($value));
            if ($scalar !== '') {
                return $scalar;
            }
        }

        return '';
    }

    private function extractEan(array $row): string
    {
        $ean = trim($this->normalizeScalar($this->pick($row, ['eanCodeAsText', 'eanCode', 'ean'])));
        return ltrim($ean);
    }

    private function extractClassification(array $row): string
    {
        $value = $this->pick($row, ['productClassification', 'productClassificationCode', 'classification', 'classificationCode']);
        if (is_array($value)) {
            $pieces = [];
            array_walk_recursive($value, function ($v) use (&$pieces) {
                if (is_scalar($v) || $v === null) {
                    $pieces[] = trim((string) $v);
                }
            });
            return trim(implode('|', array_filter($pieces)));
        }
        return trim($this->normalizeScalar($value));
    }

    private function abnormalSignals(array $row, string $article, string $description, string $externalProductCode, string $classification): array
    {
        $signals = [];

        if (preg_match('/[A-Za-z]/', $article)) {
            $signals[] = 'letters_in_article';
        }
        if (preg_match('/0{4,}$/', $article)) {
            $signals[] = 'many_trailing_zeroes';
        }
        if (stripos($article, 'EEN') !== false) {
            $signals[] = 'contains_EEN';
        }
        if ($externalProductCode === '') {
            $signals[] = 'empty_externalProductCode';
        } elseif ($externalProductCode === $article) {
            $signals[] = 'external_equals_product';
        } else {
            $signals[] = 'external_differs_product';
        }
        if ($classification !== '' && stripos($classification, 'WEB') !== false) {
            $signals[] = 'classification_WEB';
        }
        if ($description !== '' && !preg_match('/\d{2,3}$/', preg_replace('/\s+/', ' ', trim($description)))) {
            $signals[] = 'description_not_ending_with_size_like_number';
        }
        if ((bool) $this->pick($row, ['oneOff'])) {
            $signals[] = 'one_off';
        }

        return array_values(array_unique($signals));
    }

    private function guessFamilyKey(string $article, string $description): string
    {
        if (preg_match('/^(\d{11})/', $article, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{9,10})/', $article, $m)) {
            return $m[1];
        }
        if ($description !== '') {
            return mb_strtolower($description);
        }
        return $article;
    }

    private function buildFamilySummary(array $rows): array
    {
        $families = [];
        foreach ($rows as $row) {
            $key = (string) $row['family_guess'];
            if (!isset($families[$key])) {
                $families[$key] = [
                    'family_guess' => $key,
                    'count_rows' => 0,
                    'count_existing' => 0,
                    'count_missing' => 0,
                    'count_abnormal' => 0,
                    'sample_description' => (string) $row['description'],
                    'sample_articles' => [],
                ];
            }
            $families[$key]['count_rows']++;
            if ($row['kms_exists'] === '1') {
                $families[$key]['count_existing']++;
            } else {
                $families[$key]['count_missing']++;
            }
            if ($row['path'] === 'abnormal_base_candidate') {
                $families[$key]['count_abnormal']++;
            }
            if (count($families[$key]['sample_articles']) < 8) {
                $families[$key]['sample_articles'][] = $row['article'];
            }
        }

        foreach ($families as &$family) {
            $family['sample_articles'] = implode('|', $family['sample_articles']);
        }
        unset($family);

        usort($families, function ($a, $b) {
            return [$b['count_missing'], $b['count_abnormal'], $b['count_rows']] <=> [$a['count_missing'], $a['count_abnormal'], $a['count_rows']];
        });

        return array_values($families);
    }

    private function writeCsv(string $path, array $rows): void
    {
        if (empty($rows)) {
            Storage::put($path, "\xEF\xBB\xBF" . "empty\n");
            return;
        }

        $headers = array_keys(reset($rows));
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers, ';');
        foreach ($rows as $row) {
            $out = [];
            foreach ($headers as $header) {
                $out[] = is_scalar($row[$header] ?? null) || $row[$header] === null
                    ? $row[$header]
                    : json_encode($row[$header], JSON_UNESCAPED_SLASHES);
            }
            fputcsv($fh, $out, ';');
        }
        rewind($fh);
        Storage::put($path, stream_get_contents($fh));
        fclose($fh);
    }

    private function pick(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }
        return null;
    }

    private function isInactive(array $row): bool
    {
        foreach (['inactive', 'blocked', 'discontinued'] as $key) {
            $value = $row[$key] ?? null;
            if (is_bool($value) && $value) {
                return true;
            }
            if (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (is_numeric($value) && (int) $value === 1) {
                return true;
            }
        }
        return false;
    }

    private function guessColor(array $row, string $description): string
    {
        $color = trim($this->normalizeScalar($this->pick($row, ['color', 'colour'])));
        if ($color !== '') {
            return $color;
        }
        if (preg_match('/\b(navy|donkerblauw|royal blue|khaki|taupe|zwart|black|wit|white|grijs|grey|gray)\b/i', $description, $m)) {
            return $m[1];
        }
        return '';
    }

    private function guessSize(array $row, string $description): string
    {
        $size = trim($this->normalizeScalar($this->pick($row, ['size'])));
        if ($size !== '') {
            return $size;
        }
        if (preg_match('/\b(\d{2,3}|XS|S|M|L|XL|XXL|XXXL)\b/i', $description, $m)) {
            return $m[1];
        }
        return '';
    }

    private function normalizeNumber($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return trim((string) $value);
    }

    private function normalizeScalar($value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }
        return '';
    }

    private function normalizeScalarOption($value, string $default = ''): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($value === null || $value === false) {
            return $default;
        }
        $value = trim((string) $value);
        return $value === '' ? $default : $value;
    }

    private function guessErpBaseUrl(): string
    {
        $candidates = [
            config('services.erp.base_url'),
            config('services.erp.url'),
            config('erp.base_url'),
            config('erp.url'),
            env('ERP_BASE_URL'),
            env('ERP_URL'),
            env('ERP_API_URL'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'https://api.comfortbest.nl:444/test/rest/api/v1/01';
    }

    private function guessErpUsername(): string
    {
        $candidates = [
            config('services.erp.username'),
            config('services.erp.user'),
            config('erp.username'),
            config('erp.user'),
            env('ERP_USERNAME'),
            env('ERP_USER'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function guessErpPassword(): string
    {
        $candidates = [
            config('services.erp.password'),
            config('erp.password'),
            env('ERP_PASSWORD'),
            env('ERP_PASS'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
