<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Inspiring;

// Keep default inspire command (safe)
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->describe('Display an inspiring quote');

// Show configured ERP GET resources
Artisan::command('erp:resources', function () {
    $resources = (array) config('erp_gets.resources', []);
    foreach ($resources as $r) {
        $this->line($r);
    }
})->describe('List hardcoded ERP resources allowed for GET');

/**
 * ERP GET runner
 *
 * Usage:
 *  php artisan erp:get products --limit=50 --max-pages=1
 *  php artisan erp:get products/106030168070420
 */
Artisan::command('erp:get {endpoint : ERP endpoint (e.g. products or products/123 or products?limit=10)}
    {--limit= : Page size (default from config)}
    {--offset=0 : Start offset}
    {--max-pages=1 : Max pages}
    {--no-dump : Do not write response JSON to storage/app}
    {--raw : Print raw response body}
    {--params=* : Extra query params as key=value, repeatable (e.g. --params=select=a,b --params=filter=id%20EQ%201)}',
function () {
    $baseUrl = (string) env('ERP_BASE_URL', '');
    $apiBasePath = (string) env('ERP_API_BASE_PATH', '');
    $admin = (string) env('ERP_ADMIN', '01');
    $user = (string) env('ERP_USER', '');
    $pass = (string) env('ERP_PASS', '');

    if ($baseUrl === '' || $apiBasePath === '') {
        $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
        return 1;
    }

    $endpoint = (string) $this->argument('endpoint');

    $limitOpt = $this->option('limit');
    $limit = is_null($limitOpt) || $limitOpt === '' ? (int) config('erp_gets.default_limit', 200) : (int) $limitOpt;
    if ($limit <= 0) $limit = (int) config('erp_gets.default_limit', 200);

    $offset = (int) $this->option('offset');
    if ($offset < 0) $offset = 0;

    $maxPages = (int) $this->option('max-pages');
    if ($maxPages <= 0) $maxPages = 1;

    $doDump = ! (bool) $this->option('no-dump');
    $raw = (bool) $this->option('raw');

    // Parse endpoint query if provided (products?foo=bar)
    $epPath = $endpoint;
    $epQuery = [];
    if (strpos($endpoint, '?') !== false) {
        [$epPath, $q] = explode('?', $endpoint, 2);
        parse_str($q, $epQuery);
    }

    // Extra params: --params=key=value
    $extra = [];
    foreach ((array) $this->option('params') as $kv) {
        if (!is_string($kv) || strpos($kv, '=') === false) continue;
        [$k, $v] = explode('=', $kv, 2);
        $k = trim($k);
        if ($k === '') continue;
        $extra[$k] = $v;
    }

    // Normalize URL parts
    $baseUrl = rtrim($baseUrl, '/');
    $apiBasePath = '/' . trim($apiBasePath, '/');
    $admin = trim($admin, '/');
    $epPath = ltrim($epPath, '/');

    $fullPath = $apiBasePath . '/' . $admin . '/' . $epPath;

    $client = Http::withBasicAuth($user, $pass)
        ->withOptions([
            // ERP often uses self-signed cert in test env
            'verify' => false,
        ])
        ->timeout(60);

    for ($page = 0; $page < $maxPages; $page++) {
        $pageOffset = $offset + ($page * $limit);

        $query = array_merge($epQuery, $extra, [
            'offset' => $pageOffset,
            'limit' => $limit,
        ]);

        $url = $baseUrl . $fullPath;

        $res = $client->get($url, $query);
        $status = $res->status();

        $this->info("GET {$epPath} (offset={$pageOffset}, limit={$limit}) -> HTTP {$status}");

        $body = $res->body();

        if (!$res->successful()) {
            $this->error('Request failed. Body:');
            $this->line($body);
            return 1;
        }

        if ($raw) {
            $this->line($body);
        } else {
            $decoded = $res->json();
            if (!is_null($decoded)) {
                $this->line(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line($body);
            }
        }

        if ($doDump) {
            $dumpDir = (string) config('erp_gets.dump_dir', 'erp_dump');
            $safeEndpoint = preg_replace('/[^a-zA-Z0-9_\-\/]+/', '_', $epPath) ?: 'endpoint';
            $safeEndpoint = str_replace('/', DIRECTORY_SEPARATOR, $safeEndpoint);

            $pathDir = $dumpDir . DIRECTORY_SEPARATOR . $safeEndpoint;
            $file = 'offset_' . $pageOffset . '_limit_' . $limit . '.json';

            Storage::disk('local')->put($pathDir . DIRECTORY_SEPARATOR . $file, $body);
            $this->comment('Dumped to storage/app/' . $pathDir . '/' . $file);
        }

        // Stop early if it looks like the last page
        $decoded = $res->json();
        if (is_array($decoded)) {
            $count = null;
            foreach (['items','data','results','value'] as $k) {
                if (isset($decoded[$k]) && is_array($decoded[$k])) {
                    $count = count($decoded[$k]);
                    break;
                }
            }
            if (!is_null($count) && $count < $limit) {
                $this->comment('Looks like last page (returned < limit).');
                break;
            }
        }
    }

    return 0;
})->describe('Run an ERP GET call and dump results to storage/app');

// Shortcut commands per configured resource: erp:get:<resource>
foreach ((array) config('erp_gets.resources', []) as $r) {
    $name = 'erp:get:' . $r;

    Artisan::command($name . '
        {--limit= : Page size}
        {--offset=0 : Start offset}
        {--max-pages=1 : Max pages}
        {--no-dump : Do not dump}
        {--raw : Print raw body}
        {--params=* : Extra query params as key=value}',
    function () use ($r) {
        $args = ['endpoint' => $r];
        $this->call('erp:get', $args + [
            '--limit' => $this->option('limit'),
            '--offset' => $this->option('offset'),
            '--max-pages' => $this->option('max-pages'),
            '--no-dump' => $this->option('no-dump'),
            '--raw' => $this->option('raw'),
            '--params' => $this->option('params'),
        ]);
    })->describe('Shortcut for erp:get ' . $r);
}

/**
 * Sync 5 ERP products to KMS (demo flow).
 *
 * Requirements (project rules):
 * - Stop as soon as 5 products are FOUND that are valid for KMS export and we attempted to sync them.
 * - A product is NOT exported when:
 *   - products.inactive == true
 *   - productClassifications contains WEB + ZZZZZZZZZ (productCategoryCode == 'WEB' AND productGroupCode == 'ZZZZZZZZZ')
 * - In all other cases: product is eligible.
 *
 * Demo/compat:
 * - We validate EAN-13 checksum so we can safely send article_number + ean in createUpdate.
 * - We always send a non-empty name (fallback to article_number).
 * - We verify BEFORE/AFTER with getProducts(articleNumber).
 */
Artisan::command('sync:products
    {--target=5 : Stop after N attempted syncs (default 5)}
    {--offset=0 : ERP start offset}
    {--page-size=200 : ERP page size}
    {--max-pages=200 : Max ERP pages to scan}
    {--dry-run : Do not call KMS createUpdate (still counts as an attempt)}
    {--no-verify : Do not do BEFORE/AFTER KMS getProducts verification}
', function () {
    $correlationId = (string) \Illuminate\Support\Str::uuid();

    $target = (int) $this->option('target');
    if ($target <= 0) $target = 5;

    $offset = (int) $this->option('offset');
    if ($offset < 0) $offset = 0;

    $pageSize = (int) $this->option('page-size');
    if ($pageSize <= 0) $pageSize = 200;

    $maxPages = (int) $this->option('max-pages');
    if ($maxPages <= 0) $maxPages = 200;

    $dryRun = (bool) $this->option('dry-run');
    $noVerify = (bool) $this->option('no-verify');

    // ERP config (.env)
    $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
    $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
    $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');
    $erpUser = (string) env('ERP_USER', '');
    $erpPass = (string) env('ERP_PASS', '');

    if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
        $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
        return 1;
    }

    // KMS config (.env/config)
    $kmsBaseUrl = rtrim((string) (config('services.kms.base_url') ?? config('kms.base_url') ?? env('KMS_BASE_URL', 'https://www.twensokms.nl')), '/');
    $kmsNamespace = (string) (config('services.kms.namespace') ?? config('kms.namespace') ?? env('KMS_NAMESPACE', ''));
    $kmsNamespace = trim($kmsNamespace, '/');
    if ($kmsNamespace === '') {
        $this->error('Missing KMS_NAMESPACE in .env');
        return 1;
    }
    // KMS token (per implementatie-handleiding): GET https://www.twensokms.nl/oauth/{namespace}/v2/token
    // Params: client_id, client_secret, username, password, grant_type=password
    $kmsClientId = (string) env('KMS_CLIENT_ID', '');
    $kmsClientSecret = (string) env('KMS_CLIENT_SECRET', '');
    $kmsUser = (string) env('KMS_USER', '');
    $kmsPass = (string) env('KMS_PASS', '');

    if ($kmsClientId === '' || $kmsClientSecret === '' || $kmsUser === '' || $kmsPass === '') {
        $this->error('Missing one of KMS_CLIENT_ID / KMS_CLIENT_SECRET / KMS_USER / KMS_PASS in .env');
        return 1;
    }

    $tokenUrl = $kmsBaseUrl . '/oauth/' . $kmsNamespace . '/v2/token';

    $tokenResp = Http::withOptions(['verify' => false])
        ->timeout(60)
        ->get($tokenUrl, [
            'client_id' => $kmsClientId,
            'client_secret' => $kmsClientSecret,
            'username' => $kmsUser,
            'password' => $kmsPass,
            'grant_type' => 'password',
        ]);

    if (!$tokenResp->ok()) {
        $this->error('[KMS_TOKEN_ERROR] HTTP ' . $tokenResp->status() . ' body: ' . $tokenResp->body());
        return 1;
    }

    $kmsToken = (string) (($tokenResp->json()['access_token'] ?? ''));

    if ($kmsToken === '') {
        $this->error('[KMS_TOKEN_ERROR] No access_token in response.');
        return 1;
    }

    $erpHttp = Http::withBasicAuth($erpUser, $erpPass)
        ->withOptions(['verify' => false])
        ->acceptJson()
        ->timeout(90);

    $kmsHttp = Http::acceptJson()->timeout(90)->withHeaders([
        'access_token' => $kmsToken,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-Correlation-Id' => $correlationId,
    ]);

    $isValidArticle15Digits = function (?string $s): bool {
        if ($s === null) return false;
        return (bool) preg_match('/^\d{15}$/', trim($s));
    };

    $isValidEan13 = function (?string $ean): bool {
        if ($ean === null) return false;
        $ean = trim($ean);
        if ($ean === '' || $ean === '0') return false;
        if (!preg_match('/^\d{13}$/', $ean)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean[$i];
            $sum += ($i % 2 === 0) ? $digit : ($digit * 3);
        }
        $check = (10 - ($sum % 10)) % 10;
        return $check === (int) $ean[12];
    };

    $normalizeToList = function ($raw): array {
        if (!is_array($raw)) return [];
        if (array_is_list($raw)) return $raw;
        foreach (['items','data','results','value'] as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) return $raw[$k];
        }
        return array_values($raw);
    };

    $kmsGetProducts = function (string $articleNumber) use ($kmsHttp, $kmsBaseUrl, $kmsNamespace, $normalizeToList): array {
        $url = $kmsBaseUrl . '/rest/' . $kmsNamespace . '/kms/product/getProducts';
        $resp = $kmsHttp->post($url, [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $articleNumber,
        ]);
        if (!$resp->ok()) {
            return [
                'ok' => false,
                'status' => $resp->status(),
                'raw' => $resp->body(),
                'list' => [],
            ];
        }
        $json = $resp->json();
        return [
            'ok' => true,
            'status' => $resp->status(),
            'raw' => $json,
            'list' => $normalizeToList($json),
        ];
    };

    $kmsCreateUpdate = function (array $productsPayload) use ($kmsHttp, $kmsBaseUrl, $kmsNamespace): array {
        $url = $kmsBaseUrl . '/rest/' . $kmsNamespace . '/kms/product/createUpdate';
        $resp = $kmsHttp->post($url, ['products' => $productsPayload]);
        return [
            'ok' => $resp->ok(),
            'status' => $resp->status(),
            'raw' => $resp->json() ?? $resp->body(),
        ];
    };

    $attempted = 0;
    $eligibleFound = 0;
    $scanned = 0;
    $skips = [
        'INVALID_ARTICLE_15' => 0,
        'INACTIVE' => 0,
        'INVALID_EAN13' => 0,
        'WEB_ZZZ' => 0,
        'MISSING_NAME' => 0,
        'ERP_ERROR' => 0,
    ];

    $this->info("[SYNC_START] correlation_id={$correlationId} target={$target} offset={$offset} page_size={$pageSize} max_pages={$maxPages} dry_run=" . ($dryRun ? 'true' : 'false'));

    for ($page = 0; $page < $maxPages; $page++) {
        if ($attempted >= $target) break;

        $pageOffset = $offset + ($page * $pageSize);
        $erpUrl = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';
        $erpResp = $erpHttp->get($erpUrl, [
            'offset' => $pageOffset,
            'limit' => $pageSize,
            'select' => 'id,productCode,description,searchName,eanCode,inactive,price,costPrice',
        ]);

        if (!$erpResp->ok()) {
            $skips['ERP_ERROR']++;
            $this->error("[ERP_ERROR] HTTP {$erpResp->status()} offset={$pageOffset}: {$erpResp->body()}");
            break;
        }

        $rows = $normalizeToList($erpResp->json());
        if (count($rows) === 0) {
            $this->comment("[ERP_EMPTY_PAGE] offset={$pageOffset}");
            break;
        }

        foreach ($rows as $erp) {
            if ($attempted >= $target) break;
            $scanned++;

            $productCode = (string) ($erp['productCode'] ?? '');
            if (!$isValidArticle15Digits($productCode)) {
                $skips['INVALID_ARTICLE_15']++;
                continue;
            }

            $inactive = (bool) ($erp['inactive'] ?? false);
            if ($inactive) {
                $skips['INACTIVE']++;
                continue;
            }

            $eanRaw = $erp['eanCode'] ?? '';
            $ean = is_numeric($eanRaw) ? (string) (int) $eanRaw : (string) $eanRaw;
            if (!$isValidEan13($ean)) {
                $skips['INVALID_EAN13']++;
                continue;
            }

            $name = (string) ($erp['searchName'] ?? $erp['description'] ?? '');
            $name = trim($name);
            if ($name === '') {
                $skips['MISSING_NAME']++;
                continue;
            }

            $pcUrl = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/productClassifications';
            $pcResp = $erpHttp->get($pcUrl, [
                'offset' => 0,
                'limit' => 200,
                'filter' => "productCode EQ '{$productCode}'",
            ]);

            if (!$pcResp->ok()) {
                $skips['ERP_ERROR']++;
                $this->error("[ERP_ERROR] productClassifications HTTP {$pcResp->status()} productCode={$productCode}: {$pcResp->body()}");
                continue;
            }

            $classifications = $normalizeToList($pcResp->json());
            $hasWebZzz = false;
            foreach ($classifications as $c) {
                $cat = (string) ($c['productCategoryCode'] ?? '');
                $grp = (string) ($c['productGroupCode'] ?? '');
                if ($cat === 'WEB' && $grp === 'ZZZZZZZZZ') {
                    $hasWebZzz = true;
                    break;
                }
            }
            if ($hasWebZzz) {
                $skips['WEB_ZZZ']++;
                continue;
            }

            $eligibleFound++;
            $attempted++;

            $price = (float) ($erp['price'] ?? 0);
            $purchase = (float) ($erp['costPrice'] ?? 0);

            $kmsProduct = [
                'article_number' => $productCode,
                'ean' => $ean,
                'name' => $name,
                'description' => (string) ($erp['description'] ?? $name),
                'price' => $price,
                'purchase_price' => $purchase,
            ];

            $this->line("\n[ATTEMPT {$attempted}/{$target}] article={$productCode} ean={$ean} name=" . mb_substr($name, 0, 60));
            $this->comment('Eligible (inactive=false, no WEB+ZZZZZZZZZ). Attempting sync...');

            $before = null;
            if (!$noVerify) {
                $before = $kmsGetProducts($productCode);
                $this->comment('[KMS_VERIFY_BEFORE] ' . ($before['ok'] ? 'OK' : 'FAIL') . ' status=' . ($before['status'] ?? 'n/a') . ' count=' . count($before['list'] ?? []));
            }

            $createResp = null;
            if ($dryRun) {
                $createResp = ['ok' => true, 'status' => 200, 'raw' => ['dry_run' => true]];
                $this->comment('[KMS_CREATEUPDATE] DRY-RUN (no call made)');
            } else {
                $createResp = $kmsCreateUpdate([$kmsProduct]);
                $this->comment('[KMS_CREATEUPDATE] ' . ($createResp['ok'] ? 'OK' : 'FAIL') . ' status=' . ($createResp['status'] ?? 'n/a'));
            }

            $after = null;
            if (!$noVerify) {
                $after = $kmsGetProducts($productCode);
                $this->comment('[KMS_VERIFY_AFTER] ' . ($after['ok'] ? 'OK' : 'FAIL') . ' status=' . ($after['status'] ?? 'n/a') . ' count=' . count($after['list'] ?? []));
            }

            \Illuminate\Support\Facades\Log::info('[SYNC_PRODUCTS_ATTEMPT]', [
                'correlation_id' => $correlationId,
                'attempt' => $attempted,
                'target' => $target,
                'erp' => [
                    'productCode' => $productCode,
                    'inactive' => $inactive,
                    'ean' => $ean,
                ],
                'classifications_count' => count($classifications),
                'kms_payload' => $kmsProduct,
                'kms_before_count' => $before ? count($before['list'] ?? []) : null,
                'kms_createUpdate_status' => $createResp['status'] ?? null,
                'kms_createUpdate_ok' => $createResp['ok'] ?? null,
                'kms_after_count' => $after ? count($after['list'] ?? []) : null,
            ]);

            if ($attempted >= $target) {
                $this->info("\n[STOP] Reached target attempted syncs: {$target}");
                break;
            }
        }
    }

    $this->info("\n[SYNC_DONE] correlation_id={$correlationId} scanned={$scanned} eligible_found={$eligibleFound} attempted={$attempted}");
    $this->line('Skip counters: ' . json_encode($skips, JSON_UNESCAPED_SLASHES));

    return 0;
})->describe('Sync 5 eligible ERP products to KMS (stops after 5 attempted syncs).');

/**
 * Lookup ProductClassifications for a specific set of productCodes.
 *
 * Why: productClassifications contains productCategoryCode like WEB that decides if product goes to KMS.
 *
 * Usage:
 *  php artisan erp:lookup:productClassifications --products=106030168070420,500050065080560 --limit=200 --max-pages=50
 *  php artisan erp:lookup:productClassifications --file=storage/app/erp_samples/product_codes.txt --limit=200 --max-pages=50
 */
Artisan::command('erp:lookup:productClassifications
    {--products= : Comma-separated list of productCodes}
    {--file= : Path to a txt/json file with productCodes (one per line, or JSON array)}
    {--limit=200 : Page size for ERP call}
    {--offset=0 : Start offset}
    {--max-pages=50 : Max pages to scan}
    {--no-dump : Do not dump}
', function () {
    $baseUrl = (string) env('ERP_BASE_URL', '');
    $apiBasePath = (string) env('ERP_API_BASE_PATH', '');
    $admin = (string) env('ERP_ADMIN', '01');
    $user = (string) env('ERP_USER', '');
    $pass = (string) env('ERP_PASS', '');

    if ($baseUrl === '' || $apiBasePath === '') {
        $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
        return 1;
    }

    // Resolve productCodes list
    $codes = [];
    $productsOpt = (string) ($this->option('products') ?? '');
    if (trim($productsOpt) !== '') {
        $codes = array_map('trim', explode(',', $productsOpt));
    }

    $fileOpt = (string) ($this->option('file') ?? '');
    if (trim($fileOpt) !== '') {
        $path = $fileOpt;
        if (!file_exists($path)) {
            $path = base_path($fileOpt);
        }
        if (!file_exists($path)) {
            $this->error("File not found: {$fileOpt}");
            return 1;
        }

        $raw = (string) file_get_contents($path);
        $rawTrim = trim($raw);
        if ($rawTrim === '') {
            $this->error("File is empty: {$fileOpt}");
            return 1;
        }

        // JSON array support
        if (str_starts_with($rawTrim, '[')) {
            $arr = json_decode($rawTrim, true);
            if (!is_array($arr)) {
                $this->error("Invalid JSON in file: {$fileOpt}");
                return 1;
            }
            foreach ($arr as $v) {
                if (is_string($v) || is_numeric($v)) $codes[] = (string) $v;
            }
        } else {
            // newline-separated
            foreach (preg_split('/\R/', $rawTrim) as $line) {
                $line = trim((string) $line);
                if ($line !== '') $codes[] = $line;
            }
        }
    }

    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
    if (count($codes) === 0) {
        $this->error('No productCodes provided. Use --products=... or --file=...');
        return 1;
    }

    $remaining = array_fill_keys($codes, true);
    $matches = [];

    $limit = (int) $this->option('limit');
    if ($limit <= 0) $limit = 200;
    $offset = (int) $this->option('offset');
    if ($offset < 0) $offset = 0;
    $maxPages = (int) $this->option('max-pages');
    if ($maxPages <= 0) $maxPages = 1;
    $doDump = ! (bool) $this->option('no-dump');

    $baseUrl = rtrim($baseUrl, '/');
    $apiBasePath = '/' . trim($apiBasePath, '/');
    $admin = trim($admin, '/');

    $endpoint = 'productClassifications';
    $fullPath = $apiBasePath . '/' . $admin . '/' . $endpoint;

    $client = Http::withBasicAuth($user, $pass)
        ->withOptions(['verify' => false])
        ->timeout(90);

    $this->info('Looking up productClassifications for ' . count($codes) . ' productCodes...');

    for ($page = 0; $page < $maxPages; $page++) {
        $pageOffset = $offset + ($page * $limit);

        $res = $client->get($baseUrl . $fullPath, [
            'offset' => $pageOffset,
            'limit' => $limit,
        ]);

        $status = $res->status();
        $this->info("GET {$endpoint} (offset={$pageOffset}, limit={$limit}) -> HTTP {$status}");

        if (!$res->successful()) {
            $this->error('Request failed. Body:');
            $this->line($res->body());
            return 1;
        }

        $decoded = $res->json();
        if (!is_array($decoded)) {
            $this->error('Unexpected response (not JSON array). Body:');
            $this->line($res->body());
            return 1;
        }

        $pageCount = 0;
        foreach ($decoded as $row) {
            $pageCount++;
            $pc = data_get($row, 'productIdentifier.productCode');
            if (!is_string($pc) && !is_numeric($pc)) continue;
            $pc = (string) $pc;
            if (isset($remaining[$pc])) {
                $matches[] = $row;
                unset($remaining[$pc]);
            }
        }

        $this->comment('Matches so far: ' . count($matches) . ' | Remaining: ' . count($remaining));

        if (count($remaining) === 0) {
            $this->comment('All requested productCodes found.');
            break;
        }

        if ($pageCount < $limit) {
            $this->comment('Looks like last page (returned < limit).');
            break;
        }
    }

    $out = [
        'requestedProductCodes' => $codes,
        'found' => $matches,
        'missingProductCodes' => array_values(array_keys($remaining)),
    ];

    $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if ($doDump) {
        $dumpDir = (string) config('erp_gets.dump_dir', 'erp_dump');
        $pathDir = $dumpDir . DIRECTORY_SEPARATOR . 'productClassifications_lookup';
        $file = 'lookup_' . date('Ymd_His') . '.json';
        Storage::disk('local')->put($pathDir . DIRECTORY_SEPARATOR . $file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->comment('Dumped to storage/app/' . $pathDir . '/' . $file);
    }

    return 0;
})->describe('Lookup ERP productClassifications for a given list of productCodes');

/**
 * Direct filter via ERP server-side filtering (Swagger supported)
 *
 * Usage:
 *  php artisan erp:filter:productClassifications --code=106030168070420
 *  php artisan erp:filter:productClassifications --codes=106030168070420,500050065080560
 */
Artisan::command('erp:filter:productClassifications
    {--code= : Single productCode}
    {--codes= : Comma separated productCodes}
', function () {

    $baseUrl = rtrim(env('ERP_BASE_URL'), '/');
    $apiBasePath = '/' . trim(env('ERP_API_BASE_PATH'), '/');
    $admin = trim(env('ERP_ADMIN'), '/');
    $user = env('ERP_USER');
    $pass = env('ERP_PASS');

    $single = $this->option('code');
    $multiple = $this->option('codes');

    if (!$single && !$multiple) {
        $this->error('Provide --code= or --codes=');
        return 1;
    }

    if ($single) {
        $filter = "productIdentifier.productCode EQ '{$single}'";
    } else {
        $codes = explode(',', $multiple);
        $quoted = array_map(fn($c) => "'".trim($c)."'", $codes);
        $filter = "productIdentifier.productCode IN (" . implode(',', $quoted) . ")";
    }

    $url = "{$baseUrl}{$apiBasePath}/{$admin}/productClassifications";

    $res = Http::withBasicAuth($user, $pass)
        ->withOptions(['verify' => false])
        ->get($url, [
            'filter' => $filter,
            'limit' => 200,
            'offset' => 0,
        ]);

    $this->info("Filter used:");
    $this->line($filter);
    $this->line('');

    if (!$res->successful()) {
        $this->error("HTTP ".$res->status());
        $this->line($res->body());
        return 1;
    }

    $this->line(json_encode($res->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});
Artisan::command('erp:report:productClassifications
    {--products= : Comma separated productCodes}
    {--out=pc_report : Output filename prefix}',
    function () {

        $codesOpt = $this->option('products');
        if (!$codesOpt) {
            $this->error('Use --products=code1,code2');
            return 1;
        }

        $codes = array_map('trim', explode(',', $codesOpt));
        $codes = array_filter($codes);

        $baseUrl = rtrim(env('ERP_BASE_URL'), '/');
        $apiBasePath = '/' . trim(env('ERP_API_BASE_PATH'), '/');
        $admin = trim(env('ERP_ADMIN'), '/');
        $user = env('ERP_USER');
        $pass = env('ERP_PASS');

        $client = \Illuminate\Support\Facades\Http::withBasicAuth($user, $pass)
            ->withOptions(['verify' => false]);

        $results = [];

        foreach ($codes as $code) {

            $res = $client->get("{$baseUrl}{$apiBasePath}/{$admin}/productClassifications", [
                'filter' => "productCode EQ '{$code}'",
                'limit' => 200,
                'offset' => 0,
            ]);

            if ($res->successful()) {
                $results[$code] = $res->json();
            } else {
                $results[$code] = ['error' => $res->body()];
            }
        }

        $timestamp = date('Ymd_His');
        $outPrefix = $this->option('out');
        $dir = storage_path('app/erp_reports');
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $jsonPath = "{$dir}/{$outPrefix}_{$timestamp}.json";
        file_put_contents($jsonPath, json_encode($results, JSON_PRETTY_PRINT));

        $html = "<html><head><meta charset='utf-8'>
    <style>
        body{font-family:Arial}
        h2{margin-top:40px}
        table{border-collapse:collapse;width:100%}
        td,th{border:1px solid #ccc;padding:6px}
        .web{background:#c6f6c6}
    </style>
    </head><body>";
        $html .= "<h1>ERP ProductClassifications Report</h1>";

        foreach ($results as $code => $rows) {
            $html .= "<h2>Product: {$code}</h2>";

            if (!is_array($rows)) {
                $html .= "<p>Error retrieving data.</p>";
                continue;
            }

            $html .= "<table><tr><th>Category</th><th>Group</th><th>ID</th></tr>";

            foreach ($rows as $row) {
                $cat = $row['productCategoryCode'] ?? '';
                $grp = $row['productGroupCode'] ?? '';
                $id  = $row['id'] ?? '';
                $class = $cat === 'WEB' ? "class='web'" : '';
                $html .= "<tr {$class}><td>{$cat}</td><td>{$grp}</td><td>{$id}</td></tr>";
            }

            $html .= "</table>";
        }

        $html .= "</body></html>";

        $htmlPath = "{$dir}/{$outPrefix}_{$timestamp}.html";
        file_put_contents($htmlPath, $html);

        $this->info("Report generated:");
        $this->line($htmlPath);
        $this->line($jsonPath);

    });
