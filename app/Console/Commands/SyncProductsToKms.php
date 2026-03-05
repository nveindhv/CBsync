<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use App\Services\Kms\KmsPayloadEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncProductsToKms extends Command
{
    protected $signature = 'sync:products:kms
        {--target=5 : Stop after this many eligible products have been attempted}
        {--offset=0 : ERP start offset (paging mode)}
        {--page-size=200 : ERP page size (paging mode)}
        {--max-pages=50 : Max ERP pages to scan (paging mode)}
        {--dry-run : Do not call KMS createUpdate}
        {--no-verify : Skip KMS getProducts verify before/after}
        {--debug : Print debug output}
        {--contains= : Only consider ERP products where description/searchKeys contain this text (e.g. 106101)}
        {--only-codes= : Comma separated ERP productCodes to sync (skips paging)}
        {--bump-price : For debugging: set price to a unique value (forces visible change)}';

    protected $description = 'Sync ERP products to KMS. Stops after N eligible products were attempted.';

    public function handle(KmsClient $kms): int
    {
        $correlationId = (string) Str::uuid();

        $target = max(1, (int) $this->option('target'));
        $dryRun = (bool) $this->option('dry-run');
        $verify = ! (bool) $this->option('no-verify');
        $debug = (bool) $this->option('debug');
        $bumpPrice = (bool) $this->option('bump-price');

        $contains = trim((string) $this->option('contains'));
        $onlyCodesRaw = trim((string) $this->option('only-codes'));
        $onlyCodes = [];

        if ($onlyCodesRaw !== '') {
            foreach (explode(',', $onlyCodesRaw) as $c) {
                $c = trim($c);
                if ($c !== '') $onlyCodes[] = $c;
            }
            $onlyCodes = array_values(array_unique($onlyCodes));
        }

        $this->line('[SYNC_START] correlation_id='.$correlationId
            .' target='.$target
            .' dry_run=' . ($dryRun ? 'true':'false')
            . ($contains !== '' ? ' contains='.$contains : '')
            . (count($onlyCodes) ? ' only_codes='.count($onlyCodes) : '')
            . ($bumpPrice ? ' bump_price=true' : '')
        );

        // ERP config
        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');

        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }

        $httpErp = Http::timeout(90)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass));

        if ($debug) {
            $this->comment('[KMS] token_length=' . strlen($kms->getAccessToken()));
        }

        $attempted = 0;
        $eligibleFound = 0;
        $scanned = 0;

        $skip = [
            'INVALID_ARTICLE_15' => 0,
            'INACTIVE' => 0,
            'MISSING_NAME' => 0,
            'NO_WEB' => 0,
            'WEB_ZZZ' => 0,
            'FILTER_CONTAINS' => 0,
            'ERP_ERROR' => 0,
        ];

        if (count($onlyCodes) > 0) {
            foreach ($onlyCodes as $code) {
                if ($attempted >= $target) break;

                $product = $this->erpFetchSingleProduct($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $code);
                if ($product === null) {
                    $skip['ERP_ERROR']++;
                    continue;
                }

                $scanned++;
                $this->processOneProduct($kms, $httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $product, $contains, $verify, $dryRun, $debug, $bumpPrice, $correlationId, $target, $attempted, $eligibleFound, $skip);
            }

            $this->finish($correlationId, $scanned, $eligibleFound, $attempted, $skip);
            return self::SUCCESS;
        }

        // Paging mode
        $offset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));

        for ($page = 0; $page < $maxPages; $page++) {
            if ($attempted >= $target) break;

            $pageOffset = $offset + ($page * $pageSize);
            if ($debug) $this->line("[ERP_PAGE] page=".($page+1)." offset={$pageOffset} limit={$pageSize}");

            $urlProducts = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';
            $resp = $httpErp->get($urlProducts, [
                'offset' => $pageOffset,
                'limit' => $pageSize,
            ]);

            if (!$resp->ok()) {
                $skip['ERP_ERROR']++;
                $this->error('[ERP_PRODUCTS] FAIL status='.$resp->status().' body='.substr($resp->body(), 0, 300));
                break;
            }

            $items = $resp->json();
            if (!is_array($items) || count($items) === 0) break;

            foreach ($items as $product) {
                if ($attempted >= $target) break;
                if (!is_array($product)) continue;

                $scanned++;
                $this->processOneProduct($kms, $httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $product, $contains, $verify, $dryRun, $debug, $bumpPrice, $correlationId, $target, $attempted, $eligibleFound, $skip);
            }

            if (count($items) < $pageSize) break;
        }

        $this->finish($correlationId, $scanned, $eligibleFound, $attempted, $skip);
        return self::SUCCESS;
    }

    private function processOneProduct(
        KmsClient $kms,
        $httpErp,
        string $erpBaseUrl,
        string $erpApiBasePath,
        string $erpAdmin,
        array $erp,
        string $contains,
        bool $verify,
        bool $dryRun,
        bool $debug,
        bool $bumpPrice,
        string $correlationId,
        int $target,
        int &$attempted,
        int &$eligibleFound,
        array &$skip
    ): void {
        $article = (string) ($erp['productCode'] ?? '');
        if (!$this->is15Digits($article)) {
            $skip['INVALID_ARTICLE_15']++;
            return;
        }

        if ((bool) ($erp['inactive'] ?? false)) {
            $skip['INACTIVE']++;
            return;
        }

        if ($contains !== '') {
            $hay = strtoupper(
                (string)($erp['description'] ?? '') . ' ' . (string)($erp['searchKeys'] ?? '') . ' ' . (string)($erp['searchName'] ?? '')
            );
            if (strpos($hay, strtoupper($contains)) === false) {
                $skip['FILTER_CONTAINS']++;
                return;
            }
        }

        $name = trim((string) ($erp['searchName'] ?? ($erp['description'] ?? '')));
        if ($name === '') $name = $article;
        if ($name === '') {
            $skip['MISSING_NAME']++;
            return;
        }

        // Eligibility rule: require WEB, reject WEB+ZZZZZZZZZ
        $pc = $this->erpFetchClassifications($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $article);
        if ($pc === null) {
            $skip['ERP_ERROR']++;
            return;
        }

        $hasWeb = false;
        $hasWebZzz = false;
        foreach ($pc as $row) {
            $cat = (string) ($row['productCategoryCode'] ?? '');
            $grp = (string) ($row['productGroupCode'] ?? '');
            if ($cat === 'WEB') {
                $hasWeb = true;
                if ($grp === 'ZZZZZZZZZ') {
                    $hasWebZzz = true;
                    break;
                }
            }
        }

        if (!$hasWeb) {
            $skip['NO_WEB']++;
            return;
        }
        if ($hasWebZzz) {
            $skip['WEB_ZZZ']++;
            return;
        }

        $ean = '';
        if (isset($erp['eanCode']) && is_numeric($erp['eanCode'])) {
            $ean = (string) ((int) $erp['eanCode']);
        }

        $unit = trim((string) ($erp['unitCode'] ?? ''));
        if ($unit === '') $unit = 'STK';

        $vat = 21;

        // Try to extract size + color from searchKeys (Tricorp has XS/S/M etc and color "ink")
        $searchKeys = (string) ($erp['searchKeys'] ?? '');
        [$size, $color] = $this->extractSizeColor($searchKeys);

        $eligibleFound++;
        $attempted++;
        $idx = $attempted;

        $this->line('');
        $this->info(sprintf('[ATTEMPT %d/%d] article=%s ean=%s name=%s size=%s color=%s', $idx, $target, $article, $ean, $name, $size, $color));

        if ($verify) {
            $before = $this->kmsVerify($kms, $article, $ean, $correlationId);
            $this->info('[KMS_VERIFY_BEFORE] articleCount=' . $before['articleCount'] . ' eanCount=' . $before['eanCount']);
            if ($debug) {
                $this->line('[KMS_DEBUG_BEFORE_ARTICLE] ' . $before['articleRaw']);
                $this->line('[KMS_DEBUG_BEFORE_EAN] ' . $before['eanRaw']);
            }
        }

        $price = (float) ($erp['price'] ?? 0);
        if ($bumpPrice) {
            // Force a visible change for debugging (and avoid decimals issues)
            $price = (float) (time() % 100000) / 100.0;
        }

        $payloadProduct = [
            'article_number' => $article,
            'name' => $name,
            'description' => (string) ($erp['description'] ?? ''),
            'purchase_price' => (float) ($erp['costPrice'] ?? 0),
            'price' => $price,
            'unit' => $unit,
            'vAT' => $vat,
            'brand' => $name, // best effort; often brand equals searchName (TRICORP)
            'color' => $color,
            'size' => $size,
            'is_active' => 1,
            'is_deleted' => 0,
        ];
        if ($ean !== '' && $ean !== '0') $payloadProduct['ean'] = $ean;

        // v1.9: ensure matrix products actually update (type_number/type_name derived from article prefix)
        if ((bool) config('kms.sync_enrich_type_fields', true)) {
            $familyLen = (int) config('kms.family_len', 11);
            $tpl = (string) config('kms.type_name_template', 'FAMILY {type_number}');
            $payloadProduct = KmsPayloadEnricher::enrichProduct($payloadProduct, $familyLen, $tpl);
        }


        if ($dryRun) {
            $this->comment('[KMS_CREATEUPDATE] DRY-RUN (no call made)');
        } else {
            $resp = $kms->post('kms/product/createUpdate', ['products' => [$payloadProduct]], $correlationId);
            $this->info('[KMS_CREATEUPDATE] OK');
            if ($debug) $this->line('[KMS_DEBUG_CREATEUPDATE] ' . json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($verify) {
            $after = $this->kmsVerify($kms, $article, $ean, $correlationId);
            $this->info('[KMS_VERIFY_AFTER] articleCount=' . $after['articleCount'] . ' eanCount=' . $after['eanCount']);
            if ($debug) {
                $this->line('[KMS_DEBUG_AFTER_ARTICLE] ' . $after['articleRaw']);
                $this->line('[KMS_DEBUG_AFTER_EAN] ' . $after['eanRaw']);
            }
        }

        if ($attempted >= $target) {
            $this->comment('[STOP] Reached target attempted syncs: ' . $attempted);
        }
    }

    private function extractSizeColor(string $searchKeys): array
    {
        $s = ' ' . strtolower($searchKeys) . ' ';

        $sizes = ['xs','s','m','l','xl','xxl','3xl','4xl','5xl','6xl','7xl','8xl'];
        $size = '';
        foreach ($sizes as $cand) {
            if (preg_match('/\b' . preg_quote($cand, '/') . '\b/', $s)) {
                $size = strtoupper($cand);
                break;
            }
        }

        // Color: token after size, best effort (tricorp has "ink")
        $color = '';
        if ($size !== '') {
            $m = [];
            if (preg_match('/\b' . strtolower($size) . '\b\s+([a-z0-9\-\/]+)/', $s, $m)) {
                $color = trim($m[1]);
            }
        }

        return [$size, $color];
    }

    private function kmsVerify(KmsClient $kms, string $articleNumber, string $ean, string $correlationId): array
    {
        $articleRaw = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $articleNumber,
        ], $correlationId);

        $eanRaw = [];
        if ($ean !== '' && $ean !== '0') {
            $eanRaw = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 10,
                'ean' => $ean,
            ], $correlationId);
        }

        return [
            'articleCount' => is_array($articleRaw) ? count($articleRaw) : 0,
            'eanCount' => is_array($eanRaw) ? count($eanRaw) : 0,
            'articleRaw' => json_encode($articleRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'eanRaw' => json_encode($eanRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function erpFetchSingleProduct($httpErp, string $base, string $apiPath, string $admin, string $productCode): ?array
    {
        $url = $base . $apiPath . '/' . $admin . '/products';
        $resp = $httpErp->get($url, [
            'offset' => 0,
            'limit' => 200,
            'filter' => "productCode EQ '{$productCode}'",
        ]);

        if (!$resp->ok()) return null;
        $items = $resp->json();
        if (!is_array($items) || count($items) === 0) return null;
        $first = $items[0];
        return is_array($first) ? $first : null;
    }

    private function erpFetchClassifications($httpErp, string $base, string $apiPath, string $admin, string $productCode): ?array
    {
        $url = $base . $apiPath . '/' . $admin . '/productClassifications';
        $resp = $httpErp->get($url, [
            'offset' => 0,
            'limit' => 200,
            'filter' => "productCode EQ '{$productCode}'",
        ]);

        if (!$resp->ok()) return null;
        $items = $resp->json();
        if (!is_array($items)) return [];
        return $items;
    }

    private function is15Digits(string $s): bool
    {
        return (bool) preg_match('/^\d{15}$/', $s);
    }

    private function finish(string $correlationId, int $scanned, int $eligibleFound, int $attempted, array $skip): void
    {
        $this->line('');
        $this->info('[SYNC_DONE] correlation_id='.$correlationId.' scanned='.$scanned.' eligible_found='.$eligibleFound.' attempted='.$attempted);
        $this->line('Skip counters: ' . json_encode($skip));
    }
}
