<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * KMS field capability reverse engineer.
 *
 * Fixes (v1.10):
 * - Snapshot lookup reverted to simple getProducts lookup (offset/limit + articleNumber), because some KMS installs
 *   ignore/complain about a nested "filters" payload and then return unrelated products (e.g. first page).
 * - Normalizes getProducts responses that are keyed by product id (associative array) instead of a numeric list.
 */
class KmsReverseCapabilities extends Command
{
    protected $signature = 'kms:reverse:capabilities
        {article : Full KMS articleNumber (variant)}
        {--ean= : EAN for the variant (recommended)}
        {--fields= : Comma-separated list of logical fields to test (default: common fields)}
        {--mode=both : minimal|rich|both}
        {--type-number= : Force type_number/typeNumber in payloads (recommended for matrix families)}
        {--type-name= : Force type_name/typeName in payloads (default: FAMILY <type_number>)}
        {--family-len=11 : If deriving type_number from article prefix, use this length (default: 11)}
        {--no-derive-type : Disable automatic type_number/type_name derivation in rich mode}
        {--include-id : Include snapshot id in payload (some KMS installs require id-based update)}
        {--no-revert : Do not revert values back after testing}
        {--sleep=250 : Milliseconds to sleep between requests (avoid rate limits)}
        {--debug : Dump payloads and before/after diffs}';

    protected $description = 'Reverse engineer which KMS product fields can be updated (one-by-one) and optionally revert.';

    // payload behavior knobs
    private ?string $forcedTypeNumber = null;
    private ?string $forcedTypeName = null;
    private int $familyLen = 11;
    private bool $deriveType = true;
    private bool $includeId = false;

    public function handle(): int
    {
        $article = (string) $this->argument('article');
        $ean = $this->option('ean') ? (string) $this->option('ean') : null;
        $sleepMs = (int) $this->option('sleep');
        $debug = (bool) $this->option('debug');

        $typeNumberOpt = $this->option('type-number') ? (string) $this->option('type-number') : null;
        $typeNameOpt = $this->option('type-name') ? (string) $this->option('type-name') : null;

        $familyLen = (int) ($this->option('family-len') ?? 11);
        // IMPORTANT: On this KMS instance we observed that including type_number/type_name during UPDATE
        // calls can cause the update to be IGNORED.
        // Therefore, we only include/derive type_* keys when the user explicitly forces a type-number.
        $deriveType = (!$this->option('no-derive-type')) && ($typeNumberOpt !== null);
        $includeId = (bool) $this->option('include-id');

        $modeOpt = strtolower((string) ($this->option('mode') ?? 'both'));
        if (!in_array($modeOpt, ['minimal', 'rich', 'both'], true)) {
            $this->error('Invalid --mode. Allowed: minimal|rich|both');
            return self::FAILURE;
        }
        $modes = $modeOpt === 'both' ? ['minimal', 'rich'] : [$modeOpt];

        $this->forcedTypeNumber = $typeNumberOpt;
        $this->forcedTypeName = $typeNameOpt;
        $this->familyLen = $familyLen > 0 ? $familyLen : 11;
        $this->deriveType = (bool) $deriveType;
        $this->includeId = (bool) $includeId;

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $fields = $this->parseFields($this->option('fields'));

        $this->line('=== KMS FIELD CAPABILITY REVERSE ENGINEER (v1.12) ===');
        $this->line('Article: ' . $article);
        if ($ean) $this->line('EAN    : ' . $ean);
        $this->line('Mode   : ' . implode('+', $modes));
        $this->line('Fields : ' . implode(', ', $fields));
        $this->line('Revert : ' . ($this->option('no-revert') ? 'NO' : 'YES'));
        $this->newLine();

        $base = $this->fetchOne($kms, $article, $ean, $debug);
        if (!$base) {
            $this->error('Product not found in KMS for article=' . $article . ' (and/or ean).');
            $this->warn('Tip: first create it using your createUpdate probe with type_number/type_name.');
            return self::FAILURE;
        }

        $this->info('Current snapshot found: id=' . Arr::get($base, 'id') . ' name=' . Arr::get($base, 'name'));
        $this->newLine();

        $results = []; // logicalField => ['minimal' => status, 'rich' => status]
        foreach ($fields as $logicalField) {
            $this->line('--- Testing field: ' . $logicalField . ' ---');

            $original = Arr::get($base, $logicalField);
            $mutated = $this->mutateValue($logicalField, $original);

            if ($mutated === '__SKIP__') {
                $this->warn('SKIP (no safe mutation for this field)');
                foreach ($modes as $m) $results[$logicalField][$m] = 'SKIP';
                $this->newLine();
                continue;
            }

            foreach ($modes as $mode) {
                [$status, $usedKey, $omitTypeUsed] = $this->probeField(
                    $kms, $base, $article, $ean, $mode, $logicalField, $original, $mutated, $sleepMs, $debug
                );
                $results[$logicalField][$mode] = $status;

                // Revert to original to keep test clean.
                if (!$this->option('no-revert') && $status === 'UPDATED' && $usedKey) {
                    $revertPayload = $this->buildPayload($article, $ean, $base, $mode, [$usedKey => $original], $omitTypeUsed);
                    if ($debug) {
                        $this->line('REVERT_PAYLOAD (' . $mode . ', key=' . $usedKey . '):');
                        $this->line(json_encode($revertPayload, JSON_UNESCAPED_SLASHES));
                    }
                    $kms->post('kms/product/createUpdate', $revertPayload);
                    usleep(max(0, $sleepMs) * 1000);

                    $reverted = $this->fetchOne($kms, $article, $ean, $debug);
                    $revVal = $reverted ? Arr::get($reverted, $logicalField) : null;

                    if ($this->valuesDifferent($original, $revVal)) {
                        $this->error('REVERT FAILED (' . $mode . '): expected ' . $this->stringify($original) . ' got ' . $this->stringify($revVal));
                    } else {
                        $this->line('Reverted to original (' . $mode . ').');
                    }
                }

                $this->newLine();
            }
        }

        $this->line('=== FIELD CAPABILITY MAP ===');
        foreach ($results as $f => $byMode) {
            $left = str_pad($f, 22);
            if (count($modes) === 1) {
                $this->line($left . ' ' . ($byMode[$modes[0]] ?? 'N/A'));
            } else {
                $this->line($left . ' minimal=' . ($byMode['minimal'] ?? 'N/A') . ' rich=' . ($byMode['rich'] ?? 'N/A'));
            }
        }
        $this->newLine();

        $this->comment('Notes:');
        $this->line('- If many fields are IGNORED in minimal but UPDATED in rich, your sync must send context fields (unit/brand/color/size) and often type_number/type_name.');
        $this->line('- This command tries key aliases for a few fields (camelCase + snake_case) because KMS docs often use snake_case.');
        $this->line('- For creation, you already found type_number/type_name is the key requirement; this command is for UPDATES.');

        return self::SUCCESS;
    }

    private function parseFields($raw): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            return collect(explode(',', $raw))
                ->map(fn ($s) => trim($s))
                ->filter()
                ->values()
                ->all();
        }

        return [
            'price',
            'purchasePrice',
            'name',
            'unit',
            'brand',
            'color',
            'size',
            'active',
            'deleted',
            'vAT',
            'amount',
            'supplierName',
        ];
    }

    /**
     * Probe one logical field in one mode.
     * Returns: [status, usedKey, omitTypeUsed]
     */
    private function probeField(
        KmsClient $kms,
        array $base,
        string $article,
        ?string $ean,
        string $mode,
        string $logicalField,
        $original,
        $mutated,
        int $sleepMs,
        bool $debug
    ): array {
        $aliases = $this->fieldKeyAliases($logicalField);
        $this->line('Mode: ' . $mode . ' (keys: ' . implode(', ', $aliases) . ')');

        $autoRetryNoType = ($mode === 'rich') && (!$this->option('no-derive-type')) && (!$this->option('type-number'));

        foreach ($aliases as $key) {
            $payload = $this->buildPayload($article, $ean, $base, $mode, [$key => $mutated]);

            if ($debug) {
                $this->line('PAYLOAD (' . $mode . ', key=' . $key . '):');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $kms->post('kms/product/createUpdate', $payload);
            usleep(max(0, $sleepMs) * 1000);

            $after = $this->fetchOne($kms, $article, $ean, $debug);
            $afterValue = $after ? Arr::get($after, $logicalField) : null;
            // Consider UPDATED only when the read-back matches the attempted value.
            // If the read-back becomes null/missing, treat as UNKNOWN (potentially destructive).
            if ($afterValue === null && $this->valuesDifferent($original, $afterValue)) {
                $this->warn(sprintf('CHANGED_TO_NULL ? (rich, key=%s) before=%s after=%s', $key, $this->stringify($original), $this->stringify($afterValue)));
                return ['UNKNOWN', $key, false];
            }
            if ($afterValue !== null && $this->valuesEqual($afterValue, $mutated)) { 
                $this->info('UPDATED ✔ (' . $mode . ', key=' . $key . ') before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue));
                                return ['UPDATED', $key, false];
            }

            $this->warn('IGNORED ✖ (' . $mode . ', key=' . $key . ') before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue));

            if ($autoRetryNoType) {
                $payload2 = $this->buildPayload($article, $ean, $base, $mode, [$key => $mutated], true);

                if ($debug) {
                    $this->line('PAYLOAD (rich, key=' . $key . ', no_type retry):');
                    $this->line(json_encode($payload2, JSON_UNESCAPED_SLASHES));
                }

                $kms->post('kms/product/createUpdate', $payload2);
                usleep(max(0, $sleepMs) * 1000);

                $after2 = $this->fetchOne($kms, $article, $ean, $debug);
                $afterValue2 = $after2 ? Arr::get($after2, $logicalField) : null;
                    if ($afterValue2 === null && $this->valuesDifferent($original, $afterValue2)) {
                        $this->warn(sprintf('CHANGED_TO_NULL ? (rich, key=%s, no_type retry) before=%s after=%s', $key, $this->stringify($original), $this->stringify($afterValue2)));
                        return ['UNKNOWN', $key, true];
                    }
                    if ($afterValue2 !== null && $this->valuesEqual($afterValue2, $mutated)) { 
                    $this->info('UPDATED ✔ (rich, key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
                                        return ['UPDATED', $key, true];
                }

                $this->warn('IGNORED ✖ (rich, key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
            }
        }

        return ['IGNORED', null, false];
    }

    /**
     * Fetch a single product snapshot.
     * Handles both numeric-list and associative-by-id responses.
     */
    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 50,
            'articleNumber' => $article,
        ]);

        $items = $this->normalizeProductsResponse($res);

        // exact match by articleNumber
        foreach ($items as $p) {
            if ((string) Arr::get($p, 'articleNumber') === $article) {
                return $p;
            }
        }

        if ($ean) {
            $res2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 50,
                'ean' => $ean,
            ]);
            $items2 = $this->normalizeProductsResponse($res2);
            foreach ($items2 as $p) {
                if ((string) Arr::get($p, 'articleNumber') === $article) {
                    return $p;
                }
                if ((string) Arr::get($p, 'ean') === (string) $ean) {
                    return $p;
                }
            }
        }

        if ($debug) {
            $first = $items[0] ?? null;
            if ($first) {
                $this->warn('Snapshot lookup returned products but none match articleNumber=' . $article . '. First article=' . Arr::get($first, 'articleNumber'));
            }
        }

        return null;
    }

    private function normalizeProductsResponse($res): array
    {
        if (!is_array($res) || empty($res)) return [];

        // If numeric list, keep; if assoc keyed (e.g. by id), convert to list.
        $keys = array_keys($res);
        $isNumericList = ($keys === range(0, count($keys) - 1));
        $items = $isNumericList ? $res : array_values($res);

        // Only keep arrays
        return array_values(array_filter($items, fn ($x) => is_array($x)));
    }

    /**
     * Build payload for createUpdate.
     * - minimal: only identity + mutated fields
     * - rich: identity + context fields copied from snapshot + mutated fields
     */
    private function buildPayload(string $article, ?string $ean, array $snapshot, string $mode, array $fields, bool $omitType = false): array
    {
        $p = [
            'article_number' => $article,
            'articleNumber' => $article,
        ];
        if ($ean) $p['ean'] = $ean;

        if ($this->includeId) {
            $id = Arr::get($snapshot, 'id');
            if ($id !== null && $id !== '') $p['id'] = $id;
        }


        // type_number/type_name (force/derive)
        // In some KMS installs, sending type_* on an UPDATE can cause the mutation to be ignored.
        // When $omitType=true we intentionally DO NOT include any type fields.
        if (!$omitType) {
            $typeNumber = $this->forcedTypeNumber;
            if (!$typeNumber && $this->deriveType) {
                if (strlen($article) >= $this->familyLen && ctype_digit($article)) {
                    $typeNumber = substr($article, 0, $this->familyLen);
                }
            }
            $typeName = $this->forcedTypeName;
            if ($typeNumber && !$typeName) $typeName = 'FAMILY ' . $typeNumber;

            if ($typeNumber) {
                $p['type_number'] = $typeNumber;
                $p['typeNumber'] = $typeNumber;
            }
            if ($typeName) {
                $p['type_name'] = $typeName;
                $p['typeName'] = $typeName;
            }
        }


        if ($mode === 'rich') {
            foreach (['unit', 'brand', 'color', 'size'] as $k) {
                $v = Arr::get($snapshot, $k);
                if ($v !== null && $v !== '') $p[$k] = $v;
            }
        }

        foreach ($fields as $k => $v) $p[$k] = $v;

        return ['products' => [$p]];
    }

    private function fieldKeyAliases(string $logicalField): array
    {
        $map = [
            'purchasePrice' => ['purchasePrice', 'purchase_price'],
            'supplierName' => ['supplierName', 'supplier_name'],
            'active' => ['active', 'is_active'],
            'deleted' => ['deleted', 'is_deleted'],
            'vAT' => ['vAT', 'vAt', 'vat'],
        ];
        return $map[$logicalField] ?? [$logicalField];
    }

    private function mutateValue(string $field, $original)
    {
        switch ($field) {
            case 'price':
                return is_numeric($original) ? ((float) $original) + 0.11 : 1.23;
            case 'purchasePrice':
                if ($original === null) return 1.11;
                return is_numeric($original) ? ((float) $original) + 0.11 : 1.11;
            case 'name':
                $s = (string) ($original ?? '');
                if ($s === '') $s = 'TEST ' . $field;
                return Str::limit($s, 180, '') . ' _TEST';
            case 'unit':
                $s = (string) ($original ?? '');
                return $s === '' ? 'STK' : $s . '_T';
            case 'brand':
                $s = (string) ($original ?? '');
                return $s === '' ? 'TESTBRAND' : $s . '_T';
            case 'color':
                $s = (string) ($original ?? '');
                return $s === '' ? 'test' : $s . '_T';
            case 'size':
                if ($original === null || $original === '') return '99';
                if (is_numeric($original)) return (string) (((int) $original) + 1);
                return (string) $original . '_T';
            case 'active':
                return $original === true ? false : true;
            case 'deleted':
                return $original === true ? false : true;
            case 'vAT':
                if (!is_numeric($original)) return 21;
                $v = (int) $original;
                return $v === 21 ? 22 : 21;
            case 'amount':
                return is_numeric($original) ? ((int) $original) + 1 : 1;
            case 'supplierName':
                $s = (string) ($original ?? '');
                return $s === '' ? 'TESTSUP' : $s . '_T';
            default:
                return '__SKIP__';
        }
    }

    private function valuesDifferent($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs(((float) $a) - ((float) $b)) > 0.00001;
        }

    private function valuesEqual($a, $b): bool
    {
        return !$this->valuesDifferent($a, $b);
    }
        return $a !== $b;
    }

    private function stringify($v): string
    {
        if ($v === null) return 'null';
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        if (is_array($v)) return json_encode($v);
        return (string) $v;
    }
}
