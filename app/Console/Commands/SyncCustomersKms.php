<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class SyncCustomersKms extends Command
{
    protected $signature = 'sync:customers:kms
        {--erp-resource=debtors : ERP resource, meestal debtors of organisations}
        {--limit=5 : Maximaal aantal klanten om te verwerken}
        {--offset=0 : Start offset binnen ERP resource}
        {--page-size=200 : ERP page size}
        {--max-pages=1 : Max aantal ERP pages om te lezen}
        {--only-reference-ids= : CSV met specifieke referenceIds / debiteurcodes}
        {--live : Post echt naar KMS business/createUpdate}
        {--write-json : Schrijf audit report naar storage/app/private/kms_customer_sync}
        {--dump-payloads : Schrijf losse payload dumps}
        {--debug : Verbose logging}';

    protected $description = 'ERP API -> KMS klantensync via bewezen werkende KMS business base URL + Bearer auth.';

    public function handle(): int
    {
        $debug = (bool) $this->option('debug');
        $live = (bool) $this->option('live');
        $limit = max(1, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $erpResource = (string) $this->option('erp-resource');
        $onlyReferenceIds = $this->csvOption('only-reference-ids');

        $reportDir = storage_path('app/private/kms_customer_sync');
        if ($this->option('write-json') || $this->option('dump-payloads')) {
            if (! is_dir($reportDir)) {
                @mkdir($reportDir, 0777, true);
            }
            if ($this->option('dump-payloads') && ! is_dir($reportDir . DIRECTORY_SEPARATOR . 'payloads')) {
                @mkdir($reportDir . DIRECTORY_SEPARATOR . 'payloads', 0777, true);
            }
        }

        $this->line('=== ERP -> KMS CUSTOMER SYNC ===');
        $this->line('ERP resource : ' . $erpResource);
        $this->line('Mode         : ' . ($live ? 'LIVE' : 'DRY RUN'));

        [$kmsToken, $kmsTokenSource] = $this->obtainKmsToken($debug);
        if ($kmsToken === null) {
            $this->error('Could not obtain KMS token');
            return self::FAILURE;
        }
        if ($debug) {
            $this->line('KMS token source: ' . $kmsTokenSource);
            $this->line('KMS base      : ' . $this->kmsBaseUrl());
            $this->line('KMS auth      : Bearer token');
        }

        $organisationMap = [];
        if ($erpResource === 'debtors') {
            $organisationMap = $this->fetchOrganisationMap($pageSize, $maxPages, $debug);
            if ($debug) {
                $this->line('[ORG_MAP] loaded=' . count($organisationMap));
            }
        }

        $erpRows = $this->fetchErpRows($erpResource, $offset, $pageSize, $maxPages, $debug);
        if (count($erpRows) < 1) {
            $this->error('Geen ERP klanten opgehaald via resource: ' . $erpResource);
            return self::FAILURE;
        }

        $audit = [
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'mode' => $live ? 'live' : 'dry-run',
            'erp_resource' => $erpResource,
            'kms_base' => $this->kmsBaseUrl(),
            'kms_auth' => 'bearer',
            'items' => [],
            'summary' => [
                'erp_rows' => count($erpRows),
                'candidates' => 0,
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'verified' => 0,
                'failed' => 0,
                'skipped' => 0,
                'http_401' => 0,
            ],
        ];

        $processed = 0;
        foreach ($erpRows as $rowMeta) {
            if ($processed >= $limit) {
                break;
            }

            $normalized = $this->normalizeErpCustomerRow($rowMeta['row'], $organisationMap);

            if (! empty($onlyReferenceIds) && ! in_array((string) ($normalized['referenceId'] ?? ''), $onlyReferenceIds, true)) {
                continue;
            }

            $audit['summary']['candidates']++;
            $item = [
                'timestamp' => now()->toIso8601String(),
                'source_page' => $rowMeta['page'],
                'source_offset' => $rowMeta['offset'],
                'source_index' => $rowMeta['index'],
                'source_resource' => $erpResource,
                'source_row' => $rowMeta['row'],
                'normalized' => $normalized,
                'action' => null,
                'reason' => null,
                'kms_before' => null,
                'payload' => null,
                'response' => null,
                'kms_after' => null,
                'verified' => false,
            ];

            if (! $this->isViableCustomer($normalized)) {
                $item['action'] = 'skip';
                $item['reason'] = 'MISSING_REQUIRED_FIELDS';
                $audit['items'][] = $item;
                $audit['summary']['skipped']++;
                continue;
            }

            $before = $this->kmsBusinessLookup($kmsToken, $normalized['referenceId'], $debug);
            $item['kms_before'] = $before;
            $existsBefore = (($before['http_status'] ?? 0) >= 200 && ($before['http_status'] ?? 0) < 300 && ($before['count'] ?? 0) > 0);
            $item['action'] = $existsBefore ? 'update' : 'create';

            $payload = $this->buildKmsBusinessPayload($normalized, $before['first'] ?? null);
            $item['payload'] = $payload;

            if ($this->option('dump-payloads')) {
                $this->dumpPayload($reportDir, $normalized['referenceId'], $payload);
            }

            if ($debug) {
                $this->line('[' . strtoupper($item['action']) . '] referenceId=' . $normalized['referenceId']);
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            if ($live) {
                $item['response'] = $this->kmsBusinessCreateUpdate($kmsToken, $payload, $debug);
                $item['kms_after'] = $this->kmsBusinessLookup($kmsToken, $normalized['referenceId'], $debug);

                $responseOk = $this->responseLooksSuccessful($item['response']);
                $httpStatus = (int) ($item['response']['http_status'] ?? 200);
                $afterStatus = (int) ($item['kms_after']['http_status'] ?? 0);
                $existsAfter = ($afterStatus >= 200 && $afterStatus < 300 && (($item['kms_after']['count'] ?? 0) > 0));

                if ($httpStatus === 401 || $afterStatus === 401) {
                    $audit['summary']['http_401']++;
                    $item['reason'] = 'KMS_BUSINESS_UNAUTHORIZED';
                    $item['verified'] = false;
                } elseif (! $responseOk) {
                    $item['reason'] = 'KMS_CREATEUPDATE_NOT_SUCCESSFUL';
                    $item['verified'] = false;
                } else {
                    $item['verified'] = $existsAfter;
                    if (! $existsAfter) {
                        $item['reason'] = 'POST_OK_BUT_NOT_VERIFIED';
                    }
                }
            } else {
                $item['response'] = ['dry_run' => true];
                $item['kms_after'] = $before;
                $item['verified'] = false;
                $item['reason'] = 'DRY_RUN';
            }

            if ($item['verified']) {
                $audit['summary']['verified']++;
                if ($item['action'] === 'create') {
                    $audit['summary']['created']++;
                } else {
                    $audit['summary']['updated']++;
                }
            } elseif ($item['action'] !== 'skip') {
                $audit['summary']['failed']++;
            }

            $audit['items'][] = $item;
            $audit['summary']['processed']++;
            $processed++;
        }

        $audit['finished_at'] = now()->toIso8601String();
        if ($this->option('write-json')) {
            $file = $reportDir . DIRECTORY_SEPARATOR . 'customer_sync_' . now()->format('Ymd_His') . '.json';
            File::put($file, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('REPORT JSON : ' . $file);
        }

        $this->line(json_encode($audit['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function fetchOrganisationMap(int $pageSize, int $maxPages, bool $debug = false): array
    {
        $map = [];
        $rows = $this->fetchErpRows('organisations', 0, $pageSize, $maxPages, $debug);
        foreach ($rows as $meta) {
            $row = is_array($meta['row']) ? $meta['row'] : [];
            $code = (string) ($row['organisationCode'] ?? $row['id'] ?? '');
            if ($code === '') {
                continue;
            }
            $map[$code] = $row;
        }
        return $map;
    }

    private function fetchErpRows(string $resource, int $offset, int $pageSize, int $maxPages, bool $debug = false): array
    {
        $rows = [];
        $baseUrl = $this->buildErpBaseUrl();

        for ($page = 0; $page < $maxPages; $page++) {
            $pageOffset = $offset + ($page * $pageSize);
            $request = Http::timeout(60)->acceptJson()->withoutVerifying();
            $request = $this->applyErpAuth($request);
            $url = $baseUrl . '/' . ltrim($resource, '/');

            $response = $request->get($url, ['offset' => $pageOffset, 'limit' => $pageSize]);

            if (! $response->successful()) {
                $this->warn('ERP fetch failed [' . $resource . '] offset=' . $pageOffset . ' http=' . $response->status());
                $this->warn('ERP url=' . $url);
                break;
            }

            $list = $this->normalizeList($response->json());

            if ($debug) {
                $this->line('[ERP_PAGE] resource=' . $resource . ' offset=' . $pageOffset . ' rows=' . count($list));
                $this->line('[ERP_URL] ' . $url);
            }

            if (count($list) < 1) {
                break;
            }

            foreach ($list as $index => $row) {
                $rows[] = ['page' => $page + 1, 'offset' => $pageOffset, 'index' => $index, 'row' => $row];
            }

            if (count($list) < $pageSize) {
                break;
            }
        }

        return $rows;
    }

    private function buildErpBaseUrl(): string
    {
        $fullOverride = $this->firstEnv(['ERP_API_FULL_BASE_URL','ERP_FULL_BASE_URL']);
        if ($fullOverride !== null) {
            return rtrim($fullOverride, '/');
        }

        $hostBase = $this->firstEnv(['ERP_API_BASE_URL','ERP_BASE_URL','ERP_URL'], 'https://api.comfortbest.nl:444');
        $hostBase = rtrim($hostBase, '/');

        if (strpos($hostBase, '/rest/api/v1/') !== false) {
            return $hostBase;
        }

        $stage = $this->firstEnv(['ERP_STAGE','ERP_ENV_SEGMENT'], 'test');
        $namespace = $this->firstEnv(['ERP_NAMESPACE','ERP_API_NAMESPACE'], '01');

        return $hostBase . '/' . trim($stage, '/') . '/rest/api/v1/' . trim($namespace, '/');
    }

    private function normalizeErpCustomerRow(array $row, array $organisationMap = []): array
    {
        $referenceId = $this->firstValue($row, ['debtorCode','debtorNumber','debtor_number','customerNumber','customer_number','organisationCode','organisationNumber','organisation_number','number','code','id']);
        $organisationCode = (string) ($row['organisationCode'] ?? $referenceId ?? '');
        $org = ($organisationCode !== '' && isset($organisationMap[$organisationCode]) && is_array($organisationMap[$organisationCode]))
            ? $organisationMap[$organisationCode]
            : [];

        $name = $this->firstValue($row, ['name','companyName','company_name','organisationName','organisation_name','fullName','full_name','displayName','display_name','debtorName','debtor_name']);
        if (($name === null || $name === '') && !empty($org)) {
            $name = $this->firstValue($org, ['name','organisationName','organisation_name','displayName','display_name']);
        }

        return [
            'referenceId' => (string) $referenceId,
            'name' => (string) $name,
            'shortName' => substr((string) ($name ?: $referenceId), 0, 32),
            'phone' => (string) $this->firstValue($row, ['phone','phoneNumber','phone_number','telephone']),
            'mobile' => (string) $this->firstValue($row, ['mobile','mobileNumber','mobile_number','cellphone']),
            'email' => (string) $this->firstValue($row, ['email','emailAddress','email_address']),
            'street' => (string) $this->firstValue($row, ['street','addressStreet','address_street','invoiceStreet']),
            'houseNumber' => (string) $this->firstValue($row, ['houseNumber','house_number','addressHouseNumber','invoiceHouseNumber']),
            'zipCode' => (string) preg_replace('/\s+/', '', (string) $this->firstValue($row, ['zipCode','zip_code','postalCode','postal_code','invoiceZipCode'])),
            'city' => (string) $this->firstValue($row, ['city','town','invoiceCity']),
            'postName' => (string) $this->firstValue($row, ['postName','deliveryName','shippingName']),
            'postHouseNumber' => (string) $this->firstValue($row, ['postHouseNumber','deliveryHouseNumber','shippingHouseNumber']),
            'postZipCode' => (string) preg_replace('/\s+/', '', (string) $this->firstValue($row, ['postZipCode','deliveryZipCode','shippingZipCode'])),
            'postCity' => (string) $this->firstValue($row, ['postCity','deliveryCity','shippingCity']),
            'remark' => (string) $this->firstValue($row, ['remark','remarks','note','notes']),
        ];
    }

    private function isViableCustomer(array $normalized): bool
    {
        return trim((string) ($normalized['referenceId'] ?? '')) !== '' &&
               trim((string) ($normalized['name'] ?? '')) !== '';
    }

    private function buildKmsBusinessPayload(array $normalized, ?array $existing = null): array
    {
        $payload = [
            'referenceId' => $normalized['referenceId'],
            'name' => $normalized['name'],
            'shortName' => $normalized['shortName'],
            'phone' => $normalized['phone'],
            'mobile' => $normalized['mobile'],
            'email' => $normalized['email'],
            'street' => $normalized['street'],
            'houseNumber' => $normalized['houseNumber'],
            'zipCode' => $normalized['zipCode'],
            'city' => $normalized['city'],
            'postName' => $normalized['postName'],
            'postHouseNumber' => $normalized['postHouseNumber'],
            'postZipCode' => $normalized['postZipCode'],
            'postCity' => $normalized['postCity'],
            'remark' => $normalized['remark'],
        ];

        if (! empty($existing['id'])) {
            $payload['id'] = $existing['id'];
        }

        return array_filter($payload, static fn ($v) => $v !== null && $v !== '');
    }

    private function kmsBusinessLookup(string $token, string $referenceId, bool $debug = false): array
    {
        $raw = $this->kmsPost($token, 'kms/business/list', [
            'referenceId' => $referenceId,
            'offset' => 0,
            'limit' => 10,
        ]);

        $status = (int) ($raw['http_status'] ?? 200);
        $list = $status >= 200 && $status < 300 ? $this->normalizeList($raw['body'] ?? $raw) : [];

        if ($debug) {
            $this->line('lookup business referenceId=' . $referenceId . ' count=' . count($list) . ' http=' . $status);
        }

        return [
            'http_status' => $status,
            'count' => count($list),
            'first' => $list[0] ?? null,
            'raw' => $raw,
        ];
    }

    private function kmsBusinessCreateUpdate(string $token, array $payload, bool $debug = false): array
    {
        $raw = $this->kmsPost($token, 'kms/business/createUpdate', $payload);
        if ($debug) {
            $this->line('kms/business/createUpdate response=' . json_encode($raw, JSON_UNESCAPED_UNICODE));
        }
        return $raw;
    }

    private function kmsPost(string $token, string $endpoint, array $payload): array
    {
        $url = rtrim($this->kmsBaseUrl(), '/') . '/' . ltrim($endpoint, '/');

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withoutVerifying()
                ->withToken($token)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            return [
                'http_status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'url' => $url,
                'auth_mode' => 'bearer',
            ];
        } catch (\Throwable $e) {
            return [
                'http_status' => 0,
                'body' => ['exception' => $e->getMessage()],
                'url' => $url,
                'auth_mode' => 'bearer',
            ];
        }
    }

    private function kmsBaseUrl(): string
    {
        return rtrim($this->firstEnv(
            ['KMS_REST_BASE_URL','KMS_API_BASE_URL','KMS_BASE_URL'],
            'https://www.twensokms.nl/rest/democomfortbest'
        ), '/');
    }

    private function obtainKmsToken(bool $debug = false): array
    {
        $clientId = $this->firstEnv(['KMS_CLIENT_ID','CLIENT_ID']);
        $clientSecret = $this->firstEnv(['KMS_CLIENT_SECRET','CLIENT_SECRET']);
        $username = $this->firstEnv(['KMS_USERNAME','KMS_USER','KMS_LOGIN']);
        $password = $this->firstEnv(['KMS_PASSWORD','KMS_PASS']);
        $namespace = $this->firstEnv(['KMS_NAMESPACE','KMS_API_NAMESPACE','KMS_REST_NAMESPACE'], 'democomfortbest');
        $tokenUrl = $this->firstEnv(['KMS_TOKEN_URL'], 'https://www.twensokms.nl/oauth/' . $namespace . '/v2/token');

        if ($clientId === null || $clientSecret === null || $username === null || $password === null) {
            return [null, 'missing_env'];
        }

        $response = Http::timeout(30)->acceptJson()->withoutVerifying()->get($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]);

        if (! $response->successful()) {
            if ($debug) {
                $this->warn('KMS token http=' . $response->status() . ' body=' . $response->body());
            }
            return [null, 'http_' . $response->status()];
        }

        $json = $response->json() ?: [];
        return [$json['access_token'] ?? null, 'oauth_password'];
    }

    private function responseLooksSuccessful(array $response): bool
    {
        $status = (int) ($response['http_status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return false;
        }

        $body = $response['body'] ?? null;
        if (is_array($body) && array_key_exists('success', $body)) {
            return (bool) $body['success'];
        }

        return true;
    }

    private function applyErpAuth($request)
    {
        $user = $this->firstEnv(['ERP_USERNAME','ERP_USER']);
        $pass = $this->firstEnv(['ERP_PASSWORD','ERP_PASS']);
        $apiKey = $this->firstEnv(['ERP_API_KEY','ERP_KEY']);
        $bearer = $this->firstEnv(['ERP_BEARER_TOKEN','ERP_TOKEN']);

        if ($user !== null && $pass !== null) {
            $request = $request->withBasicAuth($user, $pass);
        }
        if ($apiKey !== null) {
            $request = $request->withHeaders(['x-api-key' => $apiKey]);
        }
        if ($bearer !== null) {
            $request = $request->withToken($bearer);
        }
        return $request;
    }

    private function firstEnv(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = env($key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }
        return $default;
    }

    private function firstValue(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        foreach ($row as $value) {
            if (is_array($value)) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $value) && $value[$key] !== null && $value[$key] !== '') {
                        return $value[$key];
                    }
                }
            }
        }
        return null;
    }

    private function normalizeList($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        if (array_values($raw) === $raw) {
            return array_values(array_filter($raw, 'is_array'));
        }
        foreach (['data','rows','items','results','businesses','customers','debtors','organisations'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return array_values(array_filter($raw[$key], 'is_array'));
            }
        }
        return array_values(array_filter($raw, 'is_array'));
    }

    private function csvOption(string $name): array
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($v) => trim((string) $v), explode(',', $value))));
    }

    private function dumpPayload(string $reportDir, string $referenceId, array $payload): void
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $referenceId);
        $file = $reportDir . DIRECTORY_SEPARATOR . 'payloads' . DIRECTORY_SEPARATOR . now()->format('Ymd_His') . '_' . $safe . '.json';
        File::put($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
