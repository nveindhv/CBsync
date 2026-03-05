<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CatalogStructureAnalyze extends Command
{
    protected $signature = 'catalog:analyze
        {--limit=5000 : Max number of ERP products to scan}
        {--offset=0 : Start offset in ERP products}
        {--page-size=200 : ERP page size}
        {--family-length=11 : Prefix length treated as family}
        {--min-variants=2 : Only report families with at least this many ERP variants}
        {--kms-check=5 : Max variants per family to check in KMS (0 = skip KMS check)}
        {--kms-sample=random : Sampling mode for KMS check: random|first}
        {--sample-size=10 : How many sample variants to print per family in debug}
        {--family= : Only show one family prefix}
        {--skip-alpha : Skip families whose prefix contains letters}
        {--dump-json : Write results to storage/app/erp_dump/catalog_analyze_*.json}
        {--debug : Print sample variants for each family}';

    protected $description = 'Analyze ERP productCode structure (family/variants) and compare existence in KMS (sample-based).';

    public function handle()
    {
        $kms = app(\App\Services\Kms\KmsClient::class);

        $limit = max(1, (int) $this->option('limit'));
        $startOffset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $familyLen = max(1, (int) $this->option('family-length'));
        $minVariants = max(1, (int) $this->option('min-variants'));
        $kmsCheck = max(0, (int) $this->option('kms-check'));
        $kmsSample = (string) ($this->option('kms-sample') ?? 'random');
        $sampleSize = max(1, (int) $this->option('sample-size'));
        $onlyFamily = trim((string) ($this->option('family') ?? ''));
        $skipAlpha = (bool) $this->option('skip-alpha');
        $dumpJson = (bool) $this->option('dump-json');
        $debug = (bool) $this->option('debug');

        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');

        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return 1;
        }

        $urlProducts = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';

        $httpErp = Http::timeout(90)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass));

        $this->info('=== CATALOG STRUCTURE ANALYZER v4 ===');
        $this->line("ERP url: {$urlProducts}");
        $this->line("Scan: offset={$startOffset} limit={$limit} pageSize={$pageSize} familyLen={$familyLen} minVariants={$minVariants} kmsCheck={$kmsCheck} kmsSample={$kmsSample}");
        if ($onlyFamily !== '') $this->line("Filter: family={$onlyFamily}");
        if ($skipAlpha) $this->line("Filter: skip-alpha=true");

        $families = [];
        $scanned = 0;
        $page = 0;

        while ($scanned < $limit) {
            $offset = $startOffset + ($page * $pageSize);
            $remaining = $limit - $scanned;
            $take = min($pageSize, $remaining);

            $this->line("[ERP_PAGE] offset={$offset} limit={$take}");

            $resp = $httpErp->get($urlProducts, ['offset' => $offset, 'limit' => $take]);

            if (!$resp->ok()) {
                $this->error('[ERP] FAIL status='.$resp->status().' body='.substr($resp->body(), 0, 300));
                return 1;
            }

            $items = $resp->json();
            if (!is_array($items) || count($items) === 0) break;

            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $code = (string) ($p['productCode'] ?? '');
                if ($code === '') continue;

                $family = substr($code, 0, $familyLen);

                if ($onlyFamily !== '' && $family !== $onlyFamily) {
                    $scanned++;
                    if ($scanned >= $limit) break;
                    continue;
                }

                $alpha = (bool) preg_match('/[A-Za-z]/', $family);
                if ($skipAlpha && $alpha) {
                    $scanned++;
                    if ($scanned >= $limit) break;
                    continue;
                }

                if (!isset($families[$family])) {
                    $families[$family] = [
                        'variants' => [],
                        'count' => 0,
                        'alpha' => $alpha,
                    ];
                }

                if (!isset($families[$family]['variants'][$code])) {
                    $families[$family]['variants'][$code] = true;
                    $families[$family]['count']++;
                }

                $scanned++;
                if ($scanned >= $limit) break;
            }

            if (count($items) < $take) break;
            $page++;
        }

        $this->line('');
        $this->info('=== ERP FAMILY DETECTION ===');

        uasort($families, fn($a, $b) => $b['count'] <=> $a['count']);

        $reportFamilies = 0;
        $report = [];

        foreach ($families as $family => $data) {
            if ($data['count'] < $minVariants) continue;
            $reportFamilies++;

            $variants = array_keys($data['variants']);

            $row = [
                'family' => $family,
                'erp_variants' => $data['count'],
                'family_length' => $familyLen,
                'alpha_family' => $data['alpha'],
                'kms_checked' => 0,
                'kms_found' => null,
                'kms_status' => null,
                'sample_variants' => [],
            ];

            $this->line('');
            $this->line("Family: {$family}  ERP_variants: {$data['count']}" . ($data['alpha'] ? '  (ALPHA)' : ''));

            if ($debug) {
                $row['sample_variants'] = array_slice($variants, 0, $sampleSize);
                $this->line('Sample variants:');
                foreach ($row['sample_variants'] as $v) $this->line(" - {$v}");
            }

            if ($kmsCheck > 0) {
                $toCheck = $variants;
                if ($kmsSample === 'random' && count($toCheck) > $kmsCheck) shuffle($toCheck);
                $toCheck = array_slice($toCheck, 0, min($kmsCheck, count($toCheck)));

                $found = 0;
                $checked = 0;

                foreach ($toCheck as $v) {
                    $checked++;
                    $r = $kms->post('kms/product/getProducts', [
                        'offset' => 0,
                        'limit' => 1,
                        'articleNumber' => $v,
                    ]);
                    if (is_array($r) && count($r) > 0) $found++;
                }

                $row['kms_checked'] = $checked;
                $row['kms_found'] = $found;

                $this->line("KMS_found_in_{$checked}: {$found}");

                if ($found === 0) {
                    $row['kms_status'] = 'likely_missing';
                    $this->warn('KMS status: family likely missing (no variants found in sample).');
                } elseif ($found < $checked) {
                    $row['kms_status'] = 'partial';
                    $this->warn('KMS status: partial (some variants missing in sample).');
                } else {
                    $row['kms_status'] = 'present';
                    $this->info('KMS status: sample fully present.');
                }
            }

            $report[] = $row;
        }

        if ($dumpJson) {
            $path = storage_path('app/erp_dump');
            if (!is_dir($path)) @mkdir($path, 0777, true);

            $file = $path . DIRECTORY_SEPARATOR . 'catalog_analyze_' . date('Ymd_His') . '_offset' . $startOffset . '_limit' . $limit . '.json';
            file_put_contents($file, json_encode([
                'meta' => [
                    'offset' => $startOffset,
                    'limit' => $limit,
                    'page_size' => $pageSize,
                    'family_length' => $familyLen,
                    'min_variants' => $minVariants,
                    'kms_check' => $kmsCheck,
                    'kms_sample' => $kmsSample,
                    'sample_size' => $sampleSize,
                    'only_family' => $onlyFamily,
                    'skip_alpha' => $skipAlpha,
                ],
                'families' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Dumped JSON report to: {$file}");
        }

        $this->line('');
        $this->info("=== DONE === Families_reported={$reportFamilies} ERP_products_scanned={$scanned}");
        return 0;
    }
}
