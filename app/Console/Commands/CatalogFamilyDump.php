<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CatalogFamilyDump extends Command
{
    protected $signature = 'catalog:family:dump
        {family : Family prefix (exact string match against productCode prefix)}
        {--family-length=11 : Prefix length used to compute family}
        {--limit=5000 : Max ERP products to scan}
        {--offset=0 : Start offset in ERP products}
        {--page-size=200 : ERP page size}
        {--kms-check=1 : If 1, check each variant in KMS; if 0 skip}
        {--dump-json : Write JSON to storage/app/erp_dump/family_dump_*.json}
        {--debug : Print first 30 variants}';

    protected $description = 'Dump all ERP variants for a family and check existence in KMS (per variant).';

    public function handle()
    {
        $kms = app(\App\Services\Kms\KmsClient::class);

        $family = (string) $this->argument('family');
        $familyLen = max(1, (int) $this->option('family-length'));
        $limit = max(1, (int) $this->option('limit'));
        $startOffset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $kmsCheck = ((int) $this->option('kms-check')) === 1;
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

        $this->info('=== CATALOG FAMILY DUMP ===');
        $this->line("Family: {$family} (familyLen={$familyLen})");
        $this->line("ERP url: {$urlProducts}");
        $this->line("Scan: offset={$startOffset} limit={$limit} pageSize={$pageSize} kmsCheck=" . ($kmsCheck ? '1':'0'));

        $variants = [];
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

                $fam = substr($code, 0, $familyLen);
                if ($fam === $family) $variants[$code] = true;

                $scanned++;
                if ($scanned >= $limit) break;
            }

            if (count($items) < $take) break;
            $page++;
        }

        $variantList = array_keys($variants);
        sort($variantList);

        $this->line('');
        $this->info("ERP variants found: " . count($variantList));

        if ($debug) {
            $this->line("First variants:");
            foreach (array_slice($variantList, 0, 30) as $v) $this->line(" - {$v}");
        }

        $kmsFound = 0;
        $perVariant = [];

        if ($kmsCheck) {
            foreach ($variantList as $v) {
                $r = $kms->post('kms/product/getProducts', [
                    'offset' => 0,
                    'limit' => 1,
                    'articleNumber' => $v,
                ]);
                $exists = is_array($r) && count($r) > 0;
                if ($exists) $kmsFound++;
                $perVariant[] = ['variant' => $v, 'kms_exists' => $exists];
            }

            $this->line("KMS exists: {$kmsFound} / " . count($variantList));
        } else {
            $perVariant = array_map(fn($v) => ['variant' => $v], $variantList);
        }

        if ($dumpJson) {
            $path = storage_path('app/erp_dump');
            if (!is_dir($path)) @mkdir($path, 0777, true);

            $file = $path . DIRECTORY_SEPARATOR . 'family_dump_' . $family . '_' . date('Ymd_His') . '_offset' . $startOffset . '_limit' . $limit . '.json';
            file_put_contents($file, json_encode([
                'meta' => [
                    'family' => $family,
                    'family_length' => $familyLen,
                    'offset' => $startOffset,
                    'limit' => $limit,
                    'page_size' => $pageSize,
                    'kms_check' => $kmsCheck,
                ],
                'variants' => $perVariant,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Dumped JSON report to: {$file}");
        }

        return 0;
    }
}
