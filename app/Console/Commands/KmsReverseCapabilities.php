<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class KmsReverseCapabilities extends Command
{
    protected $signature = 'kms:reverse:capabilities
        {article : Full KMS articleNumber (variant)}
        {--ean= : EAN for the variant (recommended)}
        {--fields= : Comma-separated list of logical fields to test}
        {--mode=both : minimal|rich|both}
        {--type-number= : Force type_number/typeNumber in payloads}
        {--type-name= : Force type_name/typeName (default: FAMILY <type_number>)}
        {--family-len=11 : Prefix length when deriving a type number}
        {--no-derive-type : Disable automatic type_number/type_name derivation}
        {--include-id : Include snapshot id in payload}
        {--no-revert : Do not revert values back after testing}
        {--sleep=250 : Milliseconds to sleep between requests}
        {--allow-destructive : Also test active/deleted by default}
        {--debug : Dump payloads and before/after diffs}';

    protected $description = 'Reverse engineer which KMS product fields can be updated (one-by-one) and optionally revert.';

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
        // Belangrijk patroon uit jullie tests:
        // UPDATES werken hier juist vaker zonder type_number/type_name.
        // We derivëren type alleen nog als de gebruiker dat expliciet forceert.
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
        $fields = $this->parseFields($this->option('fields'), (bool) $this->option('allow-destructive'));

        $this->line('=== KMS FIELD CAPABILITY REVERSE ENGINEER (v1.15) ===');
        $this->line('Article: ' . $article);
        if ($ean) {
            $this->line('EAN    : ' . $ean);
        }
        $this->line('Mode   : ' . implode('+', $modes));
        $this->line('Fields : ' . implode(', ', $fields));
        $this->line('Revert : ' . ($this->option('no-revert') ? 'NO' : 'YES'));
        $this->newLine();

        $base = $this->fetchOne($kms, $article, $ean, $debug);
        if (!$base) {
            $this->error('Product not found in KMS for article=' . $article . ' (and/or ean).');
            $this->warn('Als dit eerder wel bestond, is het mogelijk door een no-revert test op hidden/inactive/deleted gezet.');
            $this->warn('Gebruik dan eerst kms:repair:product-visibility om hem terug zichtbaar te maken.');
            return self::FAILURE;
        }

        $this->info('Current snapshot found: id=' . Arr::get($base, 'id') . ' name=' . Arr::get($base, 'name'));
        $this->newLine();

        $results = [];
        foreach ($fields as $logicalField) {
            $this->line('--- Testing field: ' . $logicalField . ' ---');

            $original = Arr::get($base, $logicalField);
            $mutated = $this->mutateValue($logicalField, $original);

            if ($mutated === '__SKIP__') {
                $this->warn('SKIP (no safe mutation for this field)');
                foreach ($modes as $m) {
                    $results[$logicalField][$m] = 'SKIP';
                }
                $this->newLine();
                continue;
            }

            foreach ($modes as $mode) {
                [$status, $usedKey, $omitTypeUsed] = $this->probeField(
                    $kms,
                    $base,
                    $article,
                    $ean,
                    $mode,
                    $logicalField,
                    $original,
                    $mutated,
                    $sleepMs,
                    $debug
                );
                $results[$logicalField][$mode] = $status;

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
        foreach ($results as $field => $byMode) {
            $left = str_pad($field, 22);
            if (count($modes) === 1) {
                $this->line($left . ' ' . ($byMode[$modes[0]] ?? 'N/A'));
            } else {
                $this->line($left . ' minimal=' . ($byMode['minimal'] ?? 'N/A') . ' rich=' . ($byMode['rich'] ?? 'N/A'));
            }
        }

        $this->newLine();
        $this->comment('Notes:');
        $this->line('- In jullie KMS lijken UPDATES juist beter te werken zonder type_number/type_name.');
        $this->line('- CREATE / eerste upsert lijkt nog steeds een ander pad te zijn dan UPDATE op bestaand artikel.');
        $this->line('- active/deleted zijn standaard expres uit de default testset gehaald, omdat die producten kunnen verbergen.');

        return self::SUCCESS;
    }

    private function parseFields($raw, bool $allowDestructive): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            return collect(explode(',', $raw))
                ->map(fn ($s) => trim($s))
                ->filter()
                ->values()
                ->all();
        }

        $safe = [
            'price',
            'purchasePrice',
            'name',
            'unit',
            'brand',
            'color',
            'size',
            'vAT',
            'amount',
            'supplierName',
        ];

        if ($allowDestructive) {
            $safe[] = 'active';
            $safe[] = 'deleted';
        }

        return $safe;
    }

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
            $payload = $this->buildPayload($article, $ean, $base, $mode, [$key => $mutated], false);
            if ($debug) {
                $this->line('PAYLOAD (' . $mode . ', key=' . $key . '):');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $kms->post('kms/product/createUpdate', $payload);
            usleep(max(0, $sleepMs) * 1000);

            $after = $this->fetchOne($kms, $article, $ean, $debug);
            $afterValue = $after ? Arr::get($after, $logicalField) : null;

            if ($afterValue === null && $this->valuesDifferent($original, $afterValue)) {
                $this->warn(sprintf('CHANGED_TO_NULL ? (%s, key=%s) before=%s after=%s', $mode, $key, $this->stringify($original), $this->stringify($afterValue)));
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
                    $this->line('PAYLOAD (' . $mode . ', key=' . $key . ', no_type retry):');
                    $this->line(json_encode($payload2, JSON_UNESCAPED_SLASHES));
                }

                $kms->post('kms/product/createUpdate', $payload2);
                usleep(max(0, $sleepMs) * 1000);

                $after2 = $this->fetchOne($kms, $article, $ean, $debug);
                $afterValue2 = $after2 ? Arr::get($after2, $logicalField) : null;

                if ($afterValue2 === null && $this->valuesDifferent($original, $afterValue2)) {
                    $this->warn(sprintf('CHANGED_TO_NULL ? (%s, key=%s, no_type retry) before=%s after=%s', $mode, $key, $this->stringify($original), $this->stringify($afterValue2)));
                    return ['UNKNOWN', $key, true];
                }
                if ($afterValue2 !== null && $this->valuesEqual($afterValue2, $mutated)) {
                    $this->info('UPDATED ✔ (' . $mode . ', key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
                    return ['UPDATED', $key, true];
                }

                $this->warn('IGNORED ✖ (' . $mode . ', key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
            }
        }

        return ['IGNORED', null, false];
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 50,
            'articleNumber' => $article,
        ]);
        $items = $this->normalizeProductsResponse($res);

        if ($debug) {
            $this->line('fetchOne article lookup count=' . count($items));
            $this->line('fetchOne article raw=' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }

        foreach ($items as $product) {
            if ((string) Arr::get($product, 'articleNumber') === $article) {
                return $product;
            }
        }

        if ($ean) {
            $res2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 50,
                'ean' => $ean,
            ]);
            $items2 = $this->normalizeProductsResponse($res2);

            if ($debug) {
                $this->line('fetchOne ean lookup count=' . count($items2));
                $this->line('fetchOne ean raw=' . json_encode($res2, JSON_UNESCAPED_SLASHES));
            }

            foreach ($items2 as $product) {
                if ((string) Arr::get($product, 'articleNumber') === $article) {
                    return $product;
                }
                if ((string) Arr::get($product, 'ean') === (string) $ean) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function normalizeProductsResponse($res): array
    {
        if (!is_array($res) || empty($res)) {
            return [];
        }

        $keys = array_keys($res);
        $isNumericList = ($keys === range(0, count($keys) - 1));
        $items = $isNumericList ? $res : array_values($res);

        return array_values(array_filter($items, fn ($x) => is_array($x)));
    }

    private function buildPayload(string $article, ?string $ean, array $snapshot, string $mode, array $fields, bool $omitType = false): array
    {
        $payload = [
            'article_number' => $article,
            'articleNumber' => $article,
        ];

        if ($ean) {
            $payload['ean'] = $ean;
        }

        if ($this->includeId) {
            $id = Arr::get($snapshot, 'id');
            if ($id !== null && $id !== '') {
                $payload['id'] = $id;
            }
        }

        if (!$omitType) {
            $typeNumber = $this->forcedTypeNumber;
            if (!$typeNumber && $this->deriveType) {
                if (strlen($article) >= $this->familyLen && ctype_digit($article)) {
                    $typeNumber = substr($article, 0, $this->familyLen);
                }
            }

            $typeName = $this->forcedTypeName;
            if ($typeNumber && !$typeName) {
                $typeName = 'FAMILY ' . $typeNumber;
            }

            if ($typeNumber) {
                $payload['type_number'] = $typeNumber;
                $payload['typeNumber'] = $typeNumber;
            }
            if ($typeName) {
                $payload['type_name'] = $typeName;
                $payload['typeName'] = $typeName;
            }
        }

        if ($mode === 'rich') {
            foreach (['unit', 'brand', 'color', 'size'] as $key) {
                $value = Arr::get($snapshot, $key);
                if ($value !== null && $value !== '') {
                    $payload[$key] = $value;
                }
            }
        }

        foreach ($fields as $key => $value) {
            $payload[$key] = $value;
        }

        return ['products' => [$payload]];
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
                if ($original === null) {
                    return 1.11;
                }
                return is_numeric($original) ? ((float) $original) + 0.11 : 1.11;
            case 'name':
                $s = (string) ($original ?? '');
                if ($s === '') {
                    $s = 'TEST name';
                }
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
                if ($original === null || $original === '') {
                    return '99';
                }
                if (is_numeric($original)) {
                    return (string) (((int) $original) + 1);
                }
                return (string) $original . '_T';
            case 'active':
            case 'deleted':
                return $original === true ? false : true;
            case 'vAT':
                if (!is_numeric($original)) {
                    return 21;
                }
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

        return $a !== $b;
    }

    private function valuesEqual($a, $b): bool
    {
        return !$this->valuesDifferent($a, $b);
    }

    private function stringify($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return (string) $value;
    }
}
