<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Services\Kms\KmsClient;

/**
 * Reverse engineer which KMS product fields can be UPDATED for an existing product.
 *
 * v1.8 additions:
 *  - --mode=minimal|rich|both  (default: both)
 *  - "rich" payload can include context fields from the current snapshot (unit/brand/color/size/type_*),
 *    because KMS often ignores partial payloads.
 *  - Field key aliases: try both camelCase and snake_case for known fields (purchase_price, supplier_name, is_active, ...).
 */
class KmsReverseCapabilities extends Command
{
    protected $signature = 'kms:reverse:capabilities {article : Full KMS articleNumber (variant)}
        {--ean= : EAN for the variant (recommended)}
        {--fields= : Comma-separated list of logical fields to test (default: common fields)}
        {--mode=both : minimal|rich|both (default: both)}
        {--type-number= : Force type_number/typeNumber in payloads (recommended for matrix families)}
        {--type-name= : Force type_name/typeName in payloads (default: FAMILY <type_number>)}
        {--family-len=11 : If deriving type_number from article prefix, use this length (default: 11)}
        {--no-derive-type : Disable automatic type_number/type_name derivation in rich mode}
        {--include-id : Include snapshot id in payload (some KMS installs require id-based update)}
        {--no-revert : Do not revert values back after testing}
        {--sleep=250 : Milliseconds to sleep between requests (avoid rate limits)}
        {--debug : Dump payloads and before/after diffs}';

    protected $description = 'Reverse engineer which KMS product fields can be updated (one-by-one) and optionally revert.';

    // v1.8 payload behavior knobs
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
$deriveType = !$this->option('no-derive-type');
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

        $this->line('=== KMS FIELD CAPABILITY REVERSE ENGINEER (v1.8) ===');
        $this->line('Article: ' . $article);
        if ($ean) $this->line('EAN    : ' . $ean);
        $this->line('Mode   : ' . implode('+', $modes));
        $this->line('Fields : ' . implode(', ', $fields));
        $this->line('Revert : ' . ($this->option('no-revert') ? 'NO' : 'YES'));
        $this->newLine();

        $base = $this->fetchOne($kms, $article, $ean);
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
                [$status, $usedKey] = $this->probeField($kms, $base, $article, $ean, $mode, $logicalField, $original, $mutated, $sleepMs, $debug);
                $results[$logicalField][$mode] = $status;

                // Revert to original to keep test clean.
                if (!$this->option('no-revert') && $status === 'UPDATED' && $usedKey) {
                    $revertPayload = $this->buildPayload($article, $ean, $base, $mode, [$usedKey => $original]);
                    if ($debug) {
                        $this->line('REVERT_PAYLOAD (' . $mode . ', key=' . $usedKey . '):');
                        $this->line(json_encode($revertPayload, JSON_UNESCAPED_SLASHES));
                    }
                    $kms->post('kms/product/createUpdate', $revertPayload);
                    usleep(max(0, $sleepMs) * 1000);
                    $reverted = $this->fetchOne($kms, $article, $ean);
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
                $this->line($left . ' minimal=' . ($byMode['minimal'] ?? 'N/A') . '  rich=' . ($byMode['rich'] ?? 'N/A'));
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

        // Default: logical fields commonly seen in getProducts response.
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
     * Returns: [status, usedKey]
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

        foreach ($aliases as $key) {
            $payload = $this->buildPayload($article, $ean, $base, $mode, [$key => $mutated]);

            if ($debug) {
                $this->line('PAYLOAD ('. $mode . ', key=' . $key . '):');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $kms->post('kms/product/createUpdate', $payload);
            usleep(max(0, $sleepMs) * 1000);

            $after = $this->fetchOne($kms, $article, $ean);
            $afterValue = $after ? Arr::get($after, $logicalField) : null;

            $changed = $this->valuesDifferent($original, $afterValue);

            if ($changed) {
                $this->info('UPDATED ✔  (' . $mode . ', key=' . $key . ') before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue));
                return ['UPDATED', $key];
            }

            $this->warn('IGNORED ✖  (' . $mode . ', key=' . $key . ') before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue));
        }

        return ['IGNORED', null];
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean): ?array
    {
        // Prefer articleNumber exact lookup.
        $byArticle = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ]);

        if (is_array($byArticle) && count($byArticle) > 0) {
            return Arr::first($byArticle);
        }

        if ($ean) {
            $byEan = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ]);
            if (is_array($byEan) && count($byEan) > 0) {
                return Arr::first($byEan);
            }
        }

        return null;
    }

    /**
     * Build payload for createUpdate.
     * - minimal: only identity + mutated fields
     * - rich: identity + context fields copied from snapshot + mutated fields
     */
    private function buildPayload(string $article, ?string $ean, array $snapshot, string $mode, array $fields): array
    {
        $p = [
            'article_number' => $article,
            // Existing code in repo uses both keys; harmless to include.
            'articleNumber' => $article,
        ];

if ($ean) {
    $p['ean'] = $ean;
}

if ($this->includeId) {
    $id = Arr::get($snapshot, 'id');
    if ($id !== null && $id !== '') {
        $p['id'] = $id;
    }
}

// v1.8: force/derive type_number/type_name (useful when KMS ignores updates unless variant is linked to a "family")
$typeNumber = $this->forcedTypeNumber;
if (!$typeNumber && $this->deriveType) {
    // Derive from numeric article prefix, e.g. family-len=11
    if (is_string($article) && strlen($article) >= $this->familyLen && ctype_digit($article)) {
        $typeNumber = substr($article, 0, $this->familyLen);
    }
}
$typeName = $this->forcedTypeName;
if ($typeNumber && !$typeName) {
    $typeName = 'FAMILY ' . $typeNumber;
}

if ($typeNumber) {
    // Include both casings and snake/camel keys (harmless if KMS ignores unknown)
    $p['type_number'] = $typeNumber;
    $p['typeNumber'] = $typeNumber;
}
if ($typeName) {
    $p['type_name'] = $typeName;
    $p['typeName'] = $typeName;
}

if ($mode === 'rich') {
            // Copy context if present (try both casings).
            foreach ($this->richContextKeys() as $pair) {
                [$logical, $keys] = $pair;
                $value = null;
                foreach ($keys as $k) {
                    $value = Arr::get($snapshot, $k);
                    if ($value !== null && $value !== '') break;
                }
                if ($value === null || $value === '') continue;

                // Write back in BOTH snake_case + camelCase when we know the pair.
                foreach ($keys as $k) {
                    // only set if it's a "field" key (no dots)
                    if (is_string($k) && strpos($k, '.') === false) {
                        $p[$k] = $value;
                    }
                }
            }
        }

        // Apply the mutated field last.
        foreach ($fields as $k => $v) {
            $p[$k] = $v;
        }

        return ['products' => [$p]];
    }

    /**
     * List of context keys we try to include in rich payload.
     * Each entry: [logicalName, [keys...]]
     */
    private function richContextKeys(): array
    {
        return [
            ['unit', ['unit']],
            ['brand', ['brand']],
            ['color', ['color']],
            ['size', ['size']],        ];
    }

    /**
     * For a given logical field name, return the request-key variants to try.
     */
    private function fieldKeyAliases(string $logicalField): array
    {
        $map = [
            'purchasePrice' => ['purchasePrice', 'purchase_price'],
            'supplierName'  => ['supplierName', 'supplier_name'],
            'active'        => ['active', 'is_active'],
            'deleted'       => ['deleted', 'is_deleted'],
            'vAT'           => ['vAT', 'vAt', 'vat'],
        ];

        if (isset($map[$logicalField])) {
            return $map[$logicalField];
        }

        return [$logicalField];
    }

    private function mutateValue(string $field, $original)
    {
        switch ($field) {
            case 'price':
                if (!is_numeric($original)) return 1.23;
                return ((float) $original) + 0.11;

            case 'purchasePrice':
                if ($original === null) return 1.11;
                if (!is_numeric($original)) return 1.11;
                return ((float) $original) + 0.11;

            case 'name':
                $s = (string) ($original ?? '');
                if ($s === '') $s = 'TEST ' . $field;
                return Str::limit($s, 180, '') . ' _TEST';

            case 'unit':
                $s = (string) ($original ?? '');
                if ($s === '') return 'STK';
                return $s . '_T';

            case 'brand':
                $s = (string) ($original ?? '');
                if ($s === '') return 'TESTBRAND';
                return $s . '_T';

            case 'color':
                $s = (string) ($original ?? '');
                if ($s === '') return 'test';
                return $s . '_T';

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
                if (!is_numeric($original)) return 1;
                return ((int) $original) + 1;

            case 'supplierName':
                $s = (string) ($original ?? '');
                if ($s === '') return 'TESTSUP';
                return $s . '_T';

            default:
                return '__SKIP__';
        }
    }

    private function valuesDifferent($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs(((float) $a) - ((float) $b)) > 0.00001;
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
