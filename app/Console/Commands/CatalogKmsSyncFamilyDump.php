<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * catalog:kms:sync-family-dump
 *
 * v1.4
 * - Add --verify-existing: if --only-missing would skip an existing item, still fetch + verify
 * - Add --force-update: override --only-missing and still upsert existing items
 * - Make "success=true but not found" loud and add a clear hint
 * - IMPORTANT: set amount=1 and vAT=21 in payload (observed: products created without amount end up amount=0 and then price updates appear to be ignored)
 */
class CatalogKmsSyncFamilyDump extends Command
{
    protected $signature = 'catalog:kms:sync-family-dump
        {dump : Path under storage/app (or absolute) to family_dump_*.json}
        {--target=5 : Stop after this many successful creates/updates}
        {--dry-run : Do not call KMS createUpdate}
        {--debug : Extra output}
        {--only-missing : Only process variants not found in KMS (LIVE check)}
        {--verify-fields : After sync, verify fields match payload (best-effort)}
        {--verify-existing : When --only-missing would skip, still verify fields against KMS snapshot}
        {--force-update : When --only-missing, do not skip existing items}
        {--erp-no-verify : Disable SSL verification for ERP calls (fixes cURL error 60)}';

    protected $description = 'Sync ERP->KMS for one family using a family_dump JSON (no rescans).';

    public function handle(): int
    {
        $dumpArg = (string)$this->argument('dump');
        $dumpPath = $this->resolvePath($dumpArg);

        if (!is_file($dumpPath)) {
            $this->error("Dump not found: {$dumpPath}");
            return 1;
        }

        $data = json_decode((string)file_get_contents($dumpPath), true);
        if (!is_array($data)) {
            $this->error("Invalid JSON: {$dumpPath}");
            return 1;
        }

        $meta = $data['meta'] ?? [];
        $family = (string)($meta['family'] ?? '');
        $familyLen = (int)($meta['family_length'] ?? 11);

        if ($family === '') {
            $this->error("Dump meta.family missing.");
            return 1;
        }

        $target = (int)$this->option('target');
        $dryRun = (bool)$this->option('dry-run');
        $debug = (bool)$this->option('debug');
        $onlyMissing = (bool)$this->option('only-missing');
        $verifyFields = (bool)$this->option('verify-fields');
        $verifyExisting = (bool)$this->option('verify-existing');
        $forceUpdate = (bool)$this->option('force-update');
        $erpNoVerify = (bool)$this->option('erp-no-verify');

        $this->info("=== ERP -> KMS FAMILY SYNC (dump-driven) v1.4 ===");
        $this->line("dump={$dumpPath}");
        $this->line(
            "family={$family} familyLen={$familyLen} target={$target} dry_run=" . ($dryRun?'true':'false') .
            " only_missing=" . ($onlyMissing?'true':'false') .
            " verify_fields=" . ($verifyFields?'true':'false') .
            " verify_existing=" . ($verifyExisting?'true':'false') .
            " force_update=" . ($forceUpdate?'true':'false') .
            " erp_no_verify=" . ($erpNoVerify?'true':'false')
        );

        $variants = $data['variants'] ?? [];
        if (!is_array($variants) || count($variants) === 0) {
            $this->warn("No variants in dump.");
            return 0;
        }

        $kms = app(\App\Services\Kms\KmsClient::class);

        $attempted = 0;
        $succeeded = 0;
        $skippedExisting = 0;

        foreach ($variants as $row) {
            if ($succeeded >= $target) break;

            $variant = (string)($row['variant'] ?? '');
            if ($variant === '') continue;

            $attempted++;

            $erp = $this->erpFetchByProductCode($variant, $debug, $erpNoVerify);
            if ($erp === null) {
                $this->warn("[SKIP] ERP not found for productCode={$variant}");
                continue;
            }

            $ean = $this->pickEan($erp, $variant);
            $name = $this->pickName($erp, $variant, $family);
            $brand = $this->pickBrand($erp);
            $unit = $this->pickUnit($erp);
            [$color, $size] = $this->deriveColorSize($variant, $familyLen);

            $payload = [
                'products' => [[
                    'article_number' => $variant,
                    'ean' => $ean,
                    'name' => $name,
                    'description' => "ERP {$family} variant {$variant}",
                    'price' => (float)($erp['salesPrice'] ?? $erp['sales_price'] ?? 0),
                    'purchase_price' => (float)($erp['costPrice'] ?? $erp['purchase_price'] ?? 0),
                    // NOTE: without amount, KMS may store amount=0 and then silently ignore price updates later.
                    'amount' => 1,
                    // KMS field name is vAT in getProducts.
                    'vAT' => 21,
                    'brand' => $brand,
                    'unit' => $unit,
                    'color' => $color,
                    'size' => $size,
                    // Required for CREATE (proven by probe)
                    'type_number' => $family,
                    'type_name' => "FAMILY {$family}",
                    'supplier_name' => $brand,
                ]],
            ];

            $this->line("");
            $this->line("[{$attempted}] variant={$variant}");

            if ($debug) $this->line("ERP_MIN=" . json_encode($erp, JSON_UNESCAPED_SLASHES));

            $beforeA = $kms->post('kms/product/getProducts', ['offset'=>0,'limit'=>5,'articleNumber'=>$variant], (string)Str::uuid());
            $beforeE = $kms->post('kms/product/getProducts', ['offset'=>0,'limit'=>5,'ean'=>$ean], (string)Str::uuid());
            $beforeAC = is_array($beforeA) ? count($beforeA) : 0;
            $beforeEC = is_array($beforeE) ? count($beforeE) : 0;
            $this->line("BEFORE articleCount={$beforeAC} eanCount={$beforeEC}");

            // v1.3: LIVE only-missing filter (+ optional verify / force-update)
            if ($onlyMissing && ($beforeAC > 0 || $beforeEC > 0) && !$forceUpdate) {
                $skippedExisting++;
                $this->warn("SKIP_EXISTING (only-missing live): already present in KMS");

                if ($verifyExisting || $verifyFields) {
                    $snapshot = $this->pickSnapshot($beforeA, $beforeE);
                    if ($snapshot === null) {
                        $this->warn("VERIFY_EXISTING=SKIP (no snapshot)");
                    } else {
                        $this->verifyAgainstPayload($payload['products'][0], $snapshot);
                    }
                }

                continue;
            }

            if ($debug) $this->line("PAYLOAD=" . json_encode($payload, JSON_UNESCAPED_SLASHES));

            $resp = null;
            if (!$dryRun) {
                $resp = $kms->post('kms/product/createUpdate', $payload, (string)Str::uuid());
                $this->line("CREATEUPDATE=" . json_encode($resp, JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn("DRY-RUN: createUpdate not called.");
            }

            $afterA = $kms->post('kms/product/getProducts', ['offset'=>0,'limit'=>5,'articleNumber'=>$variant], (string)Str::uuid());
            $afterE = $kms->post('kms/product/getProducts', ['offset'=>0,'limit'=>5,'ean'=>$ean], (string)Str::uuid());
            $afterAC = is_array($afterA) ? count($afterA) : 0;
            $afterEC = is_array($afterE) ? count($afterE) : 0;
            $this->line("AFTER  articleCount={$afterAC} eanCount={$afterEC}");

            $createdOrFound = ($afterAC > 0 || $afterEC > 0);
            if ($createdOrFound) {
                $succeeded++;
                $this->info("RESULT=CREATED_OR_FOUND succeeded={$succeeded}/{$target}");
            } else {
                $this->error("RESULT=NOT_FOUND (createUpdate returned but product not retrievable by articleNumber/ean)");

                if (is_array($resp) && array_key_exists('success', $resp) && $resp['success'] === true) {
                    $this->warn('HINT: KMS responded success=true but GET found 0. Usually means missing required fields for creation (often type_number + type_name), or rejected value formats, or wrong KMS environment.');
                }
            }

            if ($verifyFields) {
                $snapshot = $this->pickSnapshot($afterA, $afterE);
                if ($snapshot === null) {
                    $this->warn("VERIFY=SKIP (no snapshot)"); 
                } else {
                    $this->verifyAgainstPayload($payload['products'][0], $snapshot);
                }
            }
        }

        $this->info("");
        $this->info("DONE attempted={$attempted} succeeded={$succeeded} skipped_existing={$skippedExisting} (target={$target})");

        return 0;
    }

    private function pickSnapshot($afterA, $afterE): ?array
    {
        $arr = null;
        if (is_array($afterA) && count($afterA) > 0) $arr = $afterA;
        elseif (is_array($afterE) && count($afterE) > 0) $arr = $afterE;

        if (!is_array($arr) || count($arr) === 0) return null;
        $first = array_values($arr)[0];
        return is_array($first) ? $first : null;
    }

    private function verifyAgainstPayload(array $p, array $s): void
    {
        // Snapshot keys are KMS-style: articleNumber, purchasePrice, supplierName, etc.
        $checks = [
            'article_number' => ['snapKey' => 'articleNumber', 'mode' => 'str'],
            'ean'            => ['snapKey' => 'ean',          'mode' => 'str'],
            'price'          => ['snapKey' => 'price',        'mode' => 'num'],
            'unit'           => ['snapKey' => 'unit',         'mode' => 'str'],
            'brand'          => ['snapKey' => 'brand',        'mode' => 'str'],
            'color'          => ['snapKey' => 'color',        'mode' => 'str'],
            'size'           => ['snapKey' => 'size',         'mode' => 'str'],
        ];

        $mismatches = [];
        foreach ($checks as $pKey => $cfg) {
            $snapKey = $cfg['snapKey'];
            $mode = $cfg['mode'];
            $pv = $p[$pKey] ?? null;
            $sv = $s[$snapKey] ?? null;

            if ($mode === 'num') {
                $pvN = is_numeric($pv) ? round((float)$pv, 2) : null;
                $svN = is_numeric($sv) ? round((float)$sv, 2) : null;
                if ($pvN !== $svN) $mismatches[] = "{$pKey} payload={$pvN} snapshot={$svN}";
            } else {
                $pvS = $pv === null ? '' : (string)$pv;
                $svS = $sv === null ? '' : (string)$sv;
                if ($pvS !== $svS) $mismatches[] = "{$pKey} payload=\"{$pvS}\" snapshot=\"{$svS}\"";
            }
        }

        if (count($mismatches) === 0) {
            $this->info("VERIFY=OK (key fields match snapshot)");
        } else {
            $this->warn("VERIFY=MISMATCH");
            foreach ($mismatches as $m) $this->warn(" - {$m}");
        }
    }

    private function resolvePath(string $arg): string
    {
        $argNorm = str_replace('\\', '/', $arg);
        if (str_starts_with($argNorm, 'storage/app/')) {
            $argNorm = substr($argNorm, strlen('storage/app/'));
        }
        if (str_starts_with($argNorm, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $arg)) return $arg;
        return storage_path('app/' . ltrim($argNorm, '/'));
    }

    private function erpFetchByProductCode(string $productCode, bool $debug, bool $noVerify): ?array
    {
        $base = rtrim((string)env('ERP_BASE_URL'), '/');
        $api = rtrim((string)env('ERP_API_BASE_PATH'), '/');
        $admin = (string)env('ERP_ADMIN', '01');
        if ($base === '' || $api === '') return null;

        $url = "{$base}{$api}/{$admin}/products";
        $user = (string)env('ERP_USER');
        $pass = (string)env('ERP_PASS');
        $filter = "productCode EQ '{$productCode}'";

        $req = Http::withBasicAuth($user, $pass)->timeout(60);
        if ($noVerify) $req = $req->withoutVerifying();

        $resp = $req->get($url, [
            'offset' => 0,
            'limit' => 1,
            'filter' => $filter,
        ]);

        if (!$resp->ok()) {
            if ($debug) {
                $body = substr((string)$resp->body(), 0, 500);
                $this->warn("ERP HTTP {$resp->status()} for productCode={$productCode}: {$body}");
            }
            return null;
        }

        $json = $resp->json();
        if (!is_array($json) || count($json) === 0) return null;
        $first = $json[0];
        return is_array($first) ? $first : null;
    }

    private function pickEan(array $erp, string $fallbackArticle): string
    {
        $ean = (string)($erp['eanCodeAsText'] ?? $erp['ean'] ?? '');
        if ($ean !== '') {
            $ean = ltrim($ean, '0');
            if ($ean !== '') return $ean;
        }
        $eanNum = (string)($erp['eanCode'] ?? '');
        $eanNum = preg_replace('/\D+/', '', $eanNum);
        if (strlen($eanNum) >= 8) return $eanNum;
        return $this->eanFromArticle($fallbackArticle);
    }

    private function pickName(array $erp, string $variant, string $family): string
    {
        $name = (string)($erp['searchName'] ?? '');
        if ($name === '') $name = (string)($erp['description'] ?? '');
        if ($name === '') $name = "ERP {$family}";
        return trim($name) . " {$variant}";
    }

    private function pickBrand(array $erp): string
    {
        $b = (string)($erp['searchName'] ?? '');
        return $b !== '' ? $b : 'ERP';
    }

    private function pickUnit(array $erp): string
    {
        $u = (string)($erp['unitCode'] ?? '');
        return $u !== '' ? $u : 'STK';
    }

    private function deriveColorSize(string $variant, int $familyLen): array
    {
        $digits = preg_replace('/\D+/', '', $variant);
        if (strlen($digits) >= $familyLen + 4) {
            $suffix = substr($digits, $familyLen);
            if (strlen($suffix) >= 4) {
                $color = substr($suffix, 0, 2);
                $size = substr($suffix, -2);
                return [$color, $size];
            }
        }
        $size = strlen($digits) >= 2 ? substr($digits, -2) : '';
        return ['', $size];
    }

    private function eanFromArticle(string $article): string
    {
        $digits = preg_replace('/\D+/', '', $article);
        if (strlen($digits) < 12) $base = str_pad($digits, 12, '0', STR_PAD_LEFT);
        else $base = substr($digits, -12);

        $sum = 0;
        for ($i=0; $i<12; $i++) {
            $n = (int)$base[$i];
            $pos = $i + 1;
            $sum += ($pos % 2 === 1) ? $n : (3 * $n);
        }
        $check = (10 - ($sum % 10)) % 10;
        return $base . (string)$check;
    }
}
