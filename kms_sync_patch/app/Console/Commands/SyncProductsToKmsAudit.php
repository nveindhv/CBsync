<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SyncProductsToKmsAudit extends Command
{
    protected $signature = 'sync:products:kms-audit
        {--target=500 : Stop after this many ERP products have been attempted}
        {--offset=0 : ERP start offset}
        {--page-size=200 : ERP page size}
        {--max-pages=500 : Maximum ERP pages to scan}
        {--only-codes= : Comma separated ERP productCodes to sync directly}
        {--contains= : Only sync rows where description/searchKeys/searchName contains this text}
        {--live : Actually call KMS createUpdate}
        {--no-verify : Skip GET before/after verification}
        {--after-wait=0 : Seconds to wait before GET-after verification}
        {--write-json : Write ndjson/json/csv reports}
        {--dump-payloads : Write one payload/verify snapshot file per attempted article}
        {--debug : Verbose output}';

    protected $description = 'ERP -> KMS syncer with per-article audit logging, page tracking and GET verification.';

    public function handle(): int
    {
        $runId = now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $runStartedAt = now()->toIso8601String();
        $target = max(1, (int) $this->option('target'));
        $offset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $live = (bool) $this->option('live');
        $verify = ! (bool) $this->option('no-verify');
        $afterWait = max(0, (int) $this->option('after-wait'));
        $writeJson = (bool) $this->option('write-json');
        $dumpPayloads = (bool) $this->option('dump-payloads');
        $debug = (bool) $this->option('debug');
        $contains = trim((string) $this->option('contains'));

        $onlyCodesRaw = trim((string) $this->option('only-codes'));
        $onlyCodes = array_values(array_filter(array_map('trim', explode(',', $onlyCodesRaw))));
        $onlyCodes = array_values(array_unique($onlyCodes));

        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');
        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        $kmsBaseUrl = rtrim((string) (config('services.kms.base_url') ?? config('kms.base_url') ?? env('KMS_BASE_URL', 'https://www.twensokms.nl')), '/');
        $kmsNamespace = (string) (config('services.kms.namespace') ?? config('kms.namespace') ?? env('KMS_NAMESPACE', ''));
        $kmsTokenPath = (string) env('KMS_TOKEN_PATH', '/token');
        $kmsClientId = (string) env('KMS_CLIENT_ID', '');
        $kmsClientSecret = (string) env('KMS_CLIENT_SECRET', '');
        $kmsUser = (string) env('KMS_USER', '');
        $kmsPass = (string) env('KMS_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }
        if ($kmsNamespace === '') {
            $this->error('Missing KMS_NAMESPACE in .env');
            return self::FAILURE;
        }

        $erpHttp = Http::timeout(90)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass));

        $token = $this->kmsToken($kmsBaseUrl, $kmsTokenPath, $kmsClientId, $kmsClientSecret, $kmsUser, $kmsPass);
        if ($token === null) {
            $this->error('Could not obtain KMS token');
            return self::FAILURE;
        }

        $kmsHttp = Http::timeout(90)
            ->acceptJson()
            ->withToken($token)
            ->withHeaders([
                'access_token' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        $baseDir = storage_path('app/private/kms_sync_audit/' . $runId);
        if ($writeJson || $dumpPayloads) {
            File::ensureDirectoryExists($baseDir);
            File::ensureDirectoryExists($baseDir . '/per_article');
        }

        $auditRows = [];
        $summary = [
            'run_id' => $runId,
            'run_started_at' => $runStartedAt,
            'mode' => $live ? 'live' : 'dry-run',
            'verify' => $verify,
            'after_wait' => $afterWait,
            'scanned' => 0,
            'attempted' => 0,
            'skipped_invalid_article' => 0,
            'skipped_missing_name' => 0,
            'skipped_contains' => 0,
            'erp_error' => 0,
            'write_exception' => 0,
            'write_success_not_visible' => 0,
            'verified_visible' => 0,
        ];

        $this->line('=== ERP -> KMS AUDIT SYNC ===');
        $this->line('Run ID     : ' . $runId);
        $this->line('Mode       : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Verify     : ' . ($verify ? 'YES' : 'NO'));
        $this->line('After wait : ' . $afterWait . 's');

        if (count($onlyCodes) > 0) {
            foreach ($onlyCodes as $idx => $code) {
                if ($summary['attempted'] >= $target) {
                    break;
                }
                $erp = $this->fetchSingleErpProduct($erpHttp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $code);
                if ($erp === null) {
                    $summary['erp_error']++;
                    continue;
                }
                $summary['scanned']++;
                $row = $this->processProduct($kmsHttp, $kmsBaseUrl, $kmsNamespace, $erp, 1, $offset, $idx, $contains, $live, $verify, $afterWait, $debug);
                $this->applyRowSummary($summary, $row);
                $auditRows[] = $row;
                if ($dumpPayloads) {
                    $this->dumpPerArticle($baseDir, $row);
                }
            }
        } else {
            for ($page = 0; $page < $maxPages; $page++) {
                if ($summary['attempted'] >= $target) {
                    break;
                }
                $pageOffset = $offset + ($page * $pageSize);
                $items = $this->fetchErpPage($erpHttp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $pageOffset, $pageSize);
                if ($items === null) {
                    $summary['erp_error']++;
                    break;
                }
                if (count($items) === 0) {
                    break;
                }
                foreach ($items as $idx => $erp) {
                    if ($summary['attempted'] >= $target) {
                        break;
                    }
                    if (! is_array($erp)) {
                        continue;
                    }
                    $summary['scanned']++;
                    $row = $this->processProduct($kmsHttp, $kmsBaseUrl, $kmsNamespace, $erp, $page + 1, $pageOffset, $idx, $contains, $live, $verify, $afterWait, $debug);
                    $this->applyRowSummary($summary, $row);
                    $auditRows[] = $row;
                    if ($dumpPayloads) {
                        $this->dumpPerArticle($baseDir, $row);
                    }
                }
                if (count($items) < $pageSize) {
                    break;
                }
            }
        }

        $summary['run_finished_at'] = now()->toIso8601String();

        if ($writeJson) {
            File::put($baseDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            File::put($baseDir . '/audit.ndjson', implode("
", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $auditRows)) . (count($auditRows) ? "
" : ''));
            File::put($baseDir . '/audit.json', json_encode($auditRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            File::put($baseDir . '/audit.csv', $this->toCsv($auditRows));
            $this->info('REPORT DIR : ' . $baseDir);
        }

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function processProduct($kmsHttp, string $kmsBaseUrl, string $kmsNamespace, array $erp, int $page, int $pageOffset, int $indexInPage, string $contains, bool $live, bool $verify, int $afterWait, bool $debug): array
    {
        $attemptedAt = now()->toIso8601String();
        $article = trim((string) ($erp['productCode'] ?? ''));
        $ean = $this->normalizeEan((string) ($erp['eanCodeAsText'] ?? $erp['eanCode'] ?? ''));
        $description = trim((string) ($erp['description'] ?? ''));
        $name = trim((string) ($erp['searchName'] ?? ''));
        if ($name === '') {
            $name = $description;
        }
        $searchKeys = trim((string) ($erp['searchKeys'] ?? ''));
        $unit = trim((string) ($erp['unitCode'] ?? '')) ?: 'STK';
        $brand = trim((string) ($erp['searchName'] ?? ''));
        [$color, $size] = $this->extractColorSize($searchKeys);
        $typeNumber = strlen($article) >= 9 ? substr($article, 0, 9) : '';
        $typeName = $description !== '' ? $description : $name;

        $row = [
            'attempted_at' => $attemptedAt,
            'page' => $page,
            'page_offset' => $pageOffset,
            'index_in_page' => $indexInPage,
            'article' => $article,
            'ean' => $ean,
            'name' => $name,
            'description' => $description,
            'search_keys' => $searchKeys,
            'type_number' => $typeNumber,
            'type_name' => $typeName,
            'status' => 'pending',
            'reason' => null,
            'write_response' => null,
            'before' => null,
            'after' => null,
            'verified_at' => null,
            'payload' => null,
        ];

        if (! preg_match('/^\d{15}$/', $article)) {
            $row['status'] = 'skipped_invalid_article';
            $row['reason'] = 'productCode is not 15 digits';
            return $row;
        }

        if ($contains !== '') {
            $hay = mb_strtoupper($description . ' ' . $searchKeys . ' ' . $name);
            if (! str_contains($hay, mb_strtoupper($contains))) {
                $row['status'] = 'skipped_contains';
                $row['reason'] = 'contains-filter mismatch';
                return $row;
            }
        }

        if ($name === '') {
            $row['status'] = 'skipped_missing_name';
            $row['reason'] = 'ERP row has no searchName/description';
            return $row;
        }

        $payload = [
            'products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'name' => $name,
                'description' => $description,
                'purchase_price' => (float) ($erp['costPrice'] ?? 0),
                'price' => (float) ($erp['salesPrice'] ?? $erp['price'] ?? 0),
                'unit' => $unit,
                'brand' => $brand,
                'color' => $color,
                'size' => $size,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'type_name' => $typeName,
                'typeName' => $typeName,
                'is_active' => (int) (! (bool) ($erp['inactive'] ?? false)),
                'is_deleted' => 0,
            ]],
        ];
        if ($ean !== '') {
            $payload['products'][0]['ean'] = $ean;
        }
        $payload['products'][0] = array_filter($payload['products'][0], fn ($v) => $v !== '' && $v !== null);
        $row['payload'] = $payload;

        if ($verify) {
            $row['before'] = $this->verify($kmsHttp, $kmsBaseUrl, $kmsNamespace, $article, $ean);
        }

        if ($debug) {
            $this->line(sprintf('[PAGE %d IDX %d] article=%s ean=%s', $page, $indexInPage, $article, $ean));
        }

        try {
            if ($live) {
                $resp = $this->kmsPost($kmsHttp, $kmsBaseUrl, $kmsNamespace, 'kms/product/createUpdate', $payload);
                $row['write_response'] = $resp;
            } else {
                $row['write_response'] = ['dry_run' => true];
            }
        } catch (Throwable $e) {
            $row['status'] = 'write_exception';
            $row['reason'] = $e->getMessage();
            return $row;
        }

        if ($verify) {
            if ($afterWait > 0) {
                sleep($afterWait);
            }
            $row['after'] = $this->verify($kmsHttp, $kmsBaseUrl, $kmsNamespace, $article, $ean);
            $row['verified_at'] = now()->toIso8601String();
            $articleCount = (int) ($row['after']['article_count'] ?? 0);
            $eanCount = (int) ($row['after']['ean_count'] ?? 0);
            if ($articleCount > 0 || $eanCount > 0) {
                $row['status'] = 'verified_visible';
                $row['reason'] = 'GET-after found article or EAN';
            } else {
                $row['status'] = 'write_success_not_visible';
                $row['reason'] = 'createUpdate returned but GET-after did not find article/EAN';
            }
        } else {
            $row['status'] = 'written_not_verified';
            $row['reason'] = 'no-verify enabled';
        }

        return $row;
    }

    private function applyRowSummary(array &$summary, array $row): void
    {
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['verified_visible', 'write_success_not_visible', 'write_exception', 'written_not_verified'], true)) {
            $summary['attempted']++;
        }
        if (array_key_exists($status, $summary)) {
            $summary[$status]++;
        }
    }

    private function fetchErpPage($erpHttp, string $erpBaseUrl, string $erpApiBasePath, string $erpAdmin, int $offset, int $limit): ?array
    {
        $url = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';
        $resp = $erpHttp->get($url, ['offset' => $offset, 'limit' => $limit]);
        if (! $resp->ok()) {
            return null;
        }
        $items = $resp->json();
        return is_array($items) ? $items : [];
    }

    private function fetchSingleErpProduct($erpHttp, string $erpBaseUrl, string $erpApiBasePath, string $erpAdmin, string $productCode): ?array
    {
        $url = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';
        $resp = $erpHttp->get($url, ['offset' => 0, 'limit' => 200, 'filter' => "productCode EQ '{$productCode}'"]);
        if (! $resp->ok()) {
            return null;
        }
        $items = $resp->json();
        if (! is_array($items) || ! isset($items[0]) || ! is_array($items[0])) {
            return null;
        }
        return $items[0];
    }

    private function verify($kmsHttp, string $kmsBaseUrl, string $kmsNamespace, string $article, string $ean): array
    {
        $articleRaw = $this->safeKmsPost($kmsHttp, $kmsBaseUrl, $kmsNamespace, 'kms/product/getProducts', ['offset' => 0, 'limit' => 10, 'articleNumber' => $article]);
        $eanRaw = $ean !== '' ? $this->safeKmsPost($kmsHttp, $kmsBaseUrl, $kmsNamespace, 'kms/product/getProducts', ['offset' => 0, 'limit' => 10, 'ean' => $ean]) : [];
        $articleRows = $this->normalizeRows($articleRaw);
        $eanRows = $this->normalizeRows($eanRaw);
        return [
            'article_count' => count($articleRows),
            'ean_count' => count($eanRows),
            'article_first' => $articleRows[0] ?? null,
            'ean_first' => $eanRows[0] ?? null,
            'article_raw' => $articleRaw,
            'ean_raw' => $eanRaw,
        ];
    }

    private function kmsPost($kmsHttp, string $kmsBaseUrl, string $kmsNamespace, string $path, array $payload): array
    {
        $url = $kmsBaseUrl . '/rest/' . trim($kmsNamespace, '/') . '/' . ltrim($path, '/');
        $resp = $kmsHttp->post($url, $payload);
        if (! $resp->ok()) {
            throw new \RuntimeException('KMS POST failed HTTP ' . $resp->status() . ': ' . $resp->body());
        }
        return $resp->json() ?? [];
    }

    private function safeKmsPost($kmsHttp, string $kmsBaseUrl, string $kmsNamespace, string $path, array $payload)
    {
        try {
            return $this->kmsPost($kmsHttp, $kmsBaseUrl, $kmsNamespace, $path, $payload);
        } catch (Throwable $e) {
            return ['exception' => $e->getMessage()];
        }
    }

    private function normalizeRows($response): array
    {
        if (! is_array($response)) {
            return [];
        }
        if (isset($response['products']) && is_array($response['products'])) {
            return array_values(array_filter($response['products'], 'is_array'));
        }
        if (isset($response['rows']) && is_array($response['rows'])) {
            return array_values(array_filter($response['rows'], 'is_array'));
        }
        if (isset($response['data']['products']) && is_array($response['data']['products'])) {
            return array_values(array_filter($response['data']['products'], 'is_array'));
        }
        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }
        return [];
    }

    private function kmsToken(string $baseUrl, string $tokenPath, string $clientId, string $clientSecret, string $username, string $password): ?string
    {
        $tokenUrl = rtrim($baseUrl, '/') . '/' . ltrim($tokenPath, '/');
        $response = Http::withOptions(['verify' => false])->timeout(60)->get($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]);
        if (! $response->ok()) {
            return null;
        }
        $json = $response->json();
        return is_array($json) ? ($json['access_token'] ?? null) : null;
    }

    private function normalizeEan(string $ean): string
    {
        return preg_replace('/\D+/', '', trim($ean)) ?: '';
    }

    private function extractColorSize(string $searchKeys): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $searchKeys));
        if ($clean === '') {
            return ['', ''];
        }
        $parts = explode(' ', $clean);
        $last = strtoupper((string) end($parts));
        if (preg_match('/^([0-9]{2,3}|XXXS|XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL)$/', $last)) {
            array_pop($parts);
            return [trim(implode(' ', $parts)), $last];
        }
        return [$clean, ''];
    }

    private function dumpPerArticle(string $baseDir, array $row): void
    {
        $article = preg_replace('/[^0-9A-Za-z_-]+/', '_', (string) ($row['article'] ?? 'unknown'));
        File::put($baseDir . '/per_article/' . $article . '.json', json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function toCsv(array $rows): string
    {
        $headers = ['attempted_at','verified_at','page','page_offset','index_in_page','article','ean','name','type_number','status','reason'];
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['attempted_at'] ?? '',
                $row['verified_at'] ?? '',
                $row['page'] ?? '',
                $row['page_offset'] ?? '',
                $row['index_in_page'] ?? '',
                $row['article'] ?? '',
                $row['ean'] ?? '',
                $row['name'] ?? '',
                $row['type_number'] ?? '',
                $row['status'] ?? '',
                $row['reason'] ?? '',
            ], ';');
        }
        rewind($fp);
        return stream_get_contents($fp) ?: '';
    }
}
