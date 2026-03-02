<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncProductsToKms extends Command
{
    protected $signature = 'sync:products:kms
        {--target=5 : Stop after this many eligible products have been attempted}
        {--offset=0 : ERP start offset}
        {--page-size=200 : ERP page size}
        {--max-pages=200 : Max ERP pages to scan}
        {--dry-run : Do not call KMS createUpdate}
        {--no-verify : Skip KMS getProducts verify before/after}
        {--debug-kms : Print extra KMS details}';

    protected $description = 'Sync products from ERP to KMS. Stops after N eligible products were attempted.';

    public function handle(KmsClient $kms): int
    {
        $correlationId = (string) Str::uuid();

        $target = max(1, (int) $this->option('target'));
        $offset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $dryRun = (bool) $this->option('dry-run');
        $verify = ! (bool) $this->option('no-verify');
        $debugKms = (bool) $this->option('debug-kms');

        $this->line('[SYNC_START] correlation_id='.$correlationId.' target='.$target.' offset='.$offset.' page_size='.$pageSize.' max_pages='.$maxPages.' dry_run=' . ($dryRun ? 'true':'false'));

        // ERP config (aligned with existing erp:get commands)
        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');
        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }

        if ($debugKms) {
            $this->comment('[KMS_DEBUG] Using App\\Services\\Kms\\KmsClient (same class as kms:get:* commands).');
            $this->comment('[KMS_DEBUG] Token length=' . strlen($kms->getAccessToken()));
        }

        $httpErp = Http::timeout(90)
            ->withOptions(['verify' => false])
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass))
            ->acceptJson();

        $attempted = 0;
        $eligibleFound = 0;
        $scanned = 0;

        $skip = [
            'INVALID_ARTICLE_15' => 0,
            'INACTIVE' => 0,
            'INVALID_EAN13' => 0,
            'NO_WEB' => 0,
            'WEB_ZZZ' => 0,
            'MISSING_NAME' => 0,
            'ERP_ERROR' => 0,
        ];

        for ($page = 0; $page < $maxPages; $page++) {
            if ($attempted >= $target) break;

            $pageOffset = $offset + ($page * $pageSize);

            $urlProducts = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';
            $resp = $httpErp->get($urlProducts, [
                'offset' => $pageOffset,
                'limit' => $pageSize,
            ]);

            if (!$resp->ok()) {
                $skip['ERP_ERROR']++;
                $this->error('[ERP_PRODUCTS] FAIL status='.$resp->status().' body='.$this->safeBody($resp->body()));
                break;
            }

            $data = $resp->json();
            $items = [];
            if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
                $items = $data['items'];
            } elseif (is_array($data)) {
                $items = array_is_list($data) ? $data : array_values($data);
            }

            if (count($items) === 0) {
                break; // end
            }

            foreach ($items as $erp) {
                if ($attempted >= $target) break;
                $scanned++;

                $article = (string) ($erp['productCode'] ?? '');
                $ean = (string) ($erp['ean'] ?? ($erp['eanCode'] ?? ''));
                $inactive = (bool) ($erp['inactive'] ?? false);

                if (!$this->is15Digits($article)) {
                    $skip['INVALID_ARTICLE_15']++;
                    continue;
                }
                if ($inactive) {
                    $skip['INACTIVE']++;
                    continue;
                }

                // Require a valid EAN13 if present. If empty, treat as invalid for now (strict mode).
                if ($ean === '' || !$this->isValidEan13($ean)) {
                    $skip['INVALID_EAN13']++;
                    continue;
                }

                $name = trim((string) ($erp['name'] ?? ($erp['description'] ?? ($erp['searchName'] ?? ''))));
                if ($name === '') {
                    $skip['MISSING_NAME']++;
                    continue;
                }

                // Eligibility rule (corrected):
                // - Only sync if there is a productClassification with category/code 'WEB'
                // - BUT if WEB value is all Z's (e.g. ZZZZZZZZZZ) then do NOT sync
                $webValue = $this->getWebClassificationValue($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $article);
                if ($webValue === null) {
                    $skip['NO_WEB']++;
                    continue;
                }
                if ($this->isAllZ($webValue)) {
                    $skip['WEB_ZZZ']++;
                    continue;
                }

                $eligibleFound++;
                $attempted++;

                $this->line('');
                $this->info(sprintf('[ATTEMPT %d/%d] article=%s ean=%s name=%s', $attempted, $target, $article, $ean, $name));
                $this->line('Eligible (inactive=false, WEB='.$webValue.'). Attempting sync...');

                if ($verify) {
                    try {
                        $before = $kms->post('kms/product/getProducts', [
                            'offset' => 0,
                            'limit' => 50,
                            'articleNumber' => $article,
                        ]);
                        $this->info('[KMS_VERIFY_BEFORE] OK count=' . (is_array($before) ? count($before) : 0));
                        if ($debugKms) {
                            $this->line('[KMS_DEBUG] BEFORE=' . json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        }
                    } catch (\Throwable $e) {
                        $this->error('[KMS_VERIFY_BEFORE] FAIL ' . $e->getMessage());
                    }
                }

                $payload = [
                    'products' => [[
                        'articleNumber' => $article,
                        'ean' => $ean,
                        'name' => $name,
                        'active' => true,
                        'deleted' => false,
                    ]],
                ];

                if ($dryRun) {
                    $this->comment('[KMS_CREATEUPDATE] DRY-RUN (no call made)');
                } else {
                    try {
                        $res = $kms->post('kms/product/createUpdate', $payload);
                        $this->info('[KMS_CREATEUPDATE] OK');
                        if ($debugKms) {
                            $this->line('[KMS_DEBUG] CREATEUPDATE=' . json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        }
                    } catch (\Throwable $e) {
                        $this->error('[KMS_CREATEUPDATE] FAIL ' . $e->getMessage());
                    }
                }

                if ($verify) {
                    try {
                        $after = $kms->post('kms/product/getProducts', [
                            'offset' => 0,
                            'limit' => 50,
                            'articleNumber' => $article,
                        ]);
                        $this->info('[KMS_VERIFY_AFTER] OK count=' . (is_array($after) ? count($after) : 0));
                        if ($debugKms) {
                            $this->line('[KMS_DEBUG] AFTER=' . json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        }
                    } catch (\Throwable $e) {
                        $this->error('[KMS_VERIFY_AFTER] FAIL ' . $e->getMessage());
                    }
                }

                if ($attempted >= $target) {
                    $this->comment('[STOP] Reached target attempted syncs: ' . $attempted);
                    break;
                }
            }
        }

        $this->line('');
        $this->info('[SYNC_DONE] correlation_id='.$correlationId.' scanned='.$scanned.' eligible_found='.$eligibleFound.' attempted='.$attempted);
        $this->line('Skip counters: ' . json_encode($skip));

        return self::SUCCESS;
    }

    private function getWebClassificationValue($httpErp, string $erpBaseUrl, string $erpApiBasePath, string $erpAdmin, string $productCode): ?string
    {
        $url = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/productClassifications';

        // Comfortbest ERP filter style used elsewhere: filter=productCode EQ '...'
        $resp = $httpErp->get($url, [
            'offset' => 0,
            'limit' => 200,
            'filter' => "productCode EQ '{$productCode}'",
        ]);

        if (!$resp->ok()) {
            return null;
        }

        $data = $resp->json();
        $items = [];
        if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (is_array($data)) {
            $items = array_is_list($data) ? $data : array_values($data);
        }

        foreach ($items as $row) {
            $code = (string) ($row['category'] ?? ($row['classificationCode'] ?? ($row['productClassificationCode'] ?? ($row['code'] ?? ''))));
            if (strtoupper(trim($code)) !== 'WEB') {
                continue;
            }

            $value = (string) ($row['value'] ?? ($row['classificationValue'] ?? ($row['textValue'] ?? ($row['propertiesToInclude'] ?? ($row['name'] ?? '')))));
            $value = trim($value);
            return $value === '' ? 'WEB' : $value;
        }

        return null;
    }

    private function isAllZ(string $value): bool
    {
        $v = strtoupper(trim($value));
        if ($v === '') return false;
        return (bool) preg_match('/^Z{8,}$/', $v);
    }

    private function is15Digits(string $s): bool
    {
        return (bool) preg_match('/^\d{15}$/', $s);
    }

    private function isValidEan13(string $ean): bool
    {
        if (!preg_match('/^\d{13}$/', $ean)) return false;
        $digits = array_map('intval', str_split($ean));
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ($i % 2 === 0) ? $digits[$i] : $digits[$i] * 3;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $check === $digits[12];
    }

    private function safeBody(string $body, int $max = 300): string
    {
        $body = trim($body);
        if (strlen($body) <= $max) return $body;
        return substr($body, 0, $max) . '...';
    }
}
