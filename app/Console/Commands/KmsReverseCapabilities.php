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
        {--fields= : Comma-separated list of logical fields to test (default: common fields)}
        {--mode=both : minimal|rich|both}
        {--type-number= : Force type_number/typeNumber in payloads}
        {--type-name= : Force type_name/typeName in payloads}
        {--family-len=11 : If deriving type_number from article prefix, use this length}
        {--no-derive-type : Disable automatic type_number/type_name derivation in rich mode}
        {--include-id : Include snapshot id in payload}
        {--no-revert : Do not revert values back after testing}
        {--sleep=250 : Milliseconds to sleep between requests}
        {--debug : Dump payloads and before/after diffs}';

    protected $description = 'Reverse engineer which KMS product fields can be updated (one-by-one) and optionally revert.';

    private ?string $forcedTypeNumber = null;
    private ?string $forcedTypeName = null;
    private int $familyLen = 11;
    private bool $deriveType = false;
    private bool $includeId = false;

    public function handle(): int
    {
        $article = (string) $this->argument('article');
        $ean = $this->option('ean') ? (string) $this->option('ean') : null;
        $sleepMs = (int) $this->option('sleep');
        $debug = (bool) $this->option('debug');

        $this->forcedTypeNumber = $this->option('type-number') ? (string) $this->option('type-number') : null;
        $this->forcedTypeName = $this->option('type-name') ? (string) $this->option('type-name') : null;
        $this->familyLen = max(1, (int) ($this->option('family-len') ?? 11));
        $this->deriveType = (! $this->option('no-derive-type')) && ($this->forcedTypeNumber !== null);
        $this->includeId = (bool) $this->option('include-id');

        $modeOpt = strtolower((string) ($this->option('mode') ?? 'both'));
        if (! in_array($modeOpt, ['minimal', 'rich', 'both'], true)) {
            $this->error('Invalid --mode. Allowed: minimal|rich|both');
            return self::FAILURE;
        }
        $modes = $modeOpt === 'both' ? ['minimal', 'rich'] : [$modeOpt];

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);
        $fields = $this->parseFields($this->option('fields'));

        $this->line('=== KMS FIELD CAPABILITY REVERSE ENGINEER (v1.14) ===');
        $this->line('Article: ' . $article);
        if ($ean) {
            $this->line('EAN    : ' . $ean);
        }
        $this->line('Mode   : ' . implode('+', $modes));
        $this->line('Fields : ' . implode(', ', $fields));
        $this->line('Revert : ' . ($this->option('no-revert') ? 'NO' : 'YES'));
        $this->newLine();

        $base = $this->fetchOne($kms, $article, $ean, $debug);
        if (! $base) {
            $this->error('Product not found in KMS for article=' . $article . ' (and/or ean).');
            $this->warn('Tip: first create it using your createUpdate probe with type_number/type_name.');
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
                foreach ($modes as $m) {
                    $results[$logicalField][$m] = 'SKIP';
                }
                $this->warn('SKIP');
                $this->newLine();
                continue;
            }

            foreach ($modes as $mode) {
                [$status, $usedKey, $omitType] = $this->probeField($kms, $base, $article, $ean, $mode, $logicalField, $original, $mutated, $sleepMs, $debug);
                $results[$logicalField][$mode] = $status;

                if (! $this->option('no-revert') && $status === 'UPDATED' && $usedKey) {
                    $revertPayload = $this->buildPayload($article, $ean, $base, $mode, [$usedKey => $original], $omitType);
                    if ($debug) {
                        $this->line('REVERT_PAYLOAD (' . $mode . ', key=' . $usedKey . '):');
                        $this->line(json_encode($revertPayload, JSON_UNESCAPED_SLASHES));
                    }
                    $kms->post('kms/product/createUpdate', $revertPayload);
                    usleep(max(0, $sleepMs) * 1000);
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

        return self::SUCCESS;
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false): ?array
    {
        $raw = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 50,
            'articleNumber' => $article,
        ]);
        $items = $this->normalizeProductsResponse($raw);
        if ($debug) {
            $this->line('fetchOne article lookup count=' . count($items));
            $this->line('fetchOne article raw=' . json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($items as $p) {
            if ((string) Arr::get($p, 'articleNumber') === $article) {
                return $p;
            }
        }

        if ($ean) {
            $raw2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 50,
                'ean' => $ean,
            ]);
            $items2 = $this->normalizeProductsResponse($raw2);
            if ($debug) {
                $this->line('fetchOne ean lookup count=' . count($items2));
                $this->line('fetchOne ean raw=' . json_encode($raw2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            foreach ($items2 as $p) {
                if ((string) Arr::get($p, 'articleNumber') === $article || (string) Arr::get($p, 'ean') === $ean) {
                    return $p;
                }
            }
        }

        return null;
    }

    private function normalizeProductsResponse($res): array
    {
        if (! is_array($res) || empty($res)) {
            return [];
        }
        $keys = array_keys($res);
        $isNumericList = ($keys === range(0, count($keys) - 1));
        $items = $isNumericList ? $res : array_values($res);
        return array_values(array_filter($items, fn ($x) => is_array($x)));
    }

    private function probeField(KmsClient $kms, array $base, string $article, ?string $ean, string $mode, string $logicalField, $original, $mutated, int $sleepMs, bool $debug): array
    {
        $aliases = $this->fieldKeyAliases($logicalField);
        $this->line('Mode: ' . $mode . ' (keys: ' . implode(', ', $aliases) . ')');
        $autoRetryNoType = ($mode === 'rich') && (! $this->option('type-number'));

        foreach ($aliases as $key) {
            $payload = $this->buildPayload($article, $ean, $base, $mode, [$key => $mutated], false);
            if ($debug) {
                $this->line('PAYLOAD (' . $mode . ', key=' . $key . '):');
                $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
            $kms->post('kms/product/createUpdate', $payload);
            usleep(max(0, $sleepMs) * 1000);
            $after = $this->fetchOne($kms, $article, $ean, false);
            $afterValue = $after ? Arr::get($after, $logicalField) : null;
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
                $after2 = $this->fetchOne($kms, $article, $ean, false);
                $afterValue2 = $after2 ? Arr::get($after2, $logicalField) : null;
                if ($afterValue2 !== null && $this->valuesEqual($afterValue2, $mutated)) {
                    $this->info('UPDATED ✔ (rich, key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
                    return ['UPDATED', $key, true];
                }
                $this->warn('IGNORED ✖ (rich, key=' . $key . ', no_type retry) before=' . $this->stringify($original) . ' after=' . $this->stringify($afterValue2));
            }
        }

        return ['IGNORED', null, false];
    }

    private function buildPayload(string $article, ?string $ean, array $snapshot, string $mode, array $fields, bool $omitType): array
    {
        $p = [
            'article_number' => $article,
            'articleNumber' => $article,
        ];
        if ($ean) {
            $p['ean'] = $ean;
        }
        if ($this->includeId && Arr::get($snapshot, 'id') !== null) {
            $p['id'] = Arr::get($snapshot, 'id');
        }

        if (! $omitType) {
            $typeNumber = $this->forcedTypeNumber;
            if (! $typeNumber && $this->deriveType && strlen($article) >= $this->familyLen && ctype_digit($article)) {
                $typeNumber = substr($article, 0, $this->familyLen);
            }
            $typeName = $this->forcedTypeName ?: ($typeNumber ? 'FAMILY ' . $typeNumber : null);
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
                if ($v !== null && $v !== '') {
                    $p[$k] = $v;
                }
            }
        }

        foreach ($fields as $k => $v) {
            $p[$k] = $v;
        }

        return ['products' => [$p]];
    }

    private function parseFields($raw): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            return collect(explode(',', $raw))->map(fn ($s) => trim($s))->filter()->values()->all();
        }

        return ['price', 'purchasePrice', 'name', 'unit', 'brand', 'color', 'size', 'active', 'deleted', 'vAT', 'amount', 'supplierName'];
    }

    private function fieldKeyAliases(string $logicalField): array
    {
        return [
            'purchasePrice' => ['purchasePrice', 'purchase_price'],
            'supplierName' => ['supplierName', 'supplier_name'],
            'active' => ['active', 'is_active'],
            'deleted' => ['deleted', 'is_deleted'],
            'vAT' => ['vAT', 'vAt', 'vat'],
        ][$logicalField] ?? [$logicalField];
    }

    private function mutateValue(string $field, $original)
    {
        return match ($field) {
            'price' => is_numeric($original) ? ((float) $original) + 0.11 : 1.23,
            'purchasePrice' => is_numeric($original) ? ((float) $original) + 0.11 : 1.11,
            'name' => Str::limit((string) ($original ?: 'TEST ' . $field), 180, '') . ' _TEST',
            'unit' => ($original === null || $original === '') ? 'STK' : ((string) $original . '_T'),
            'brand' => ($original === null || $original === '') ? 'TESTBRAND' : ((string) $original . '_T'),
            'color' => ($original === null || $original === '') ? 'test' : ((string) $original . '_T'),
            'size' => ($original === null || $original === '') ? '99' : (is_numeric($original) ? (string) (((int) $original) + 1) : ((string) $original . '_T')),
            'active', 'deleted' => $original === true ? false : true,
            'vAT' => (! is_numeric($original)) ? 21 : (((int) $original) === 21 ? 22 : 21),
            'amount' => is_numeric($original) ? ((int) $original) + 1 : 1,
            'supplierName' => ($original === null || $original === '') ? 'TESTSUP' : ((string) $original . '_T'),
            default => '__SKIP__',
        };
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
        return ! $this->valuesDifferent($a, $b);
    }

    private function stringify($v): string
    {
        if ($v === null) return 'null';
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return (string) $v;
    }
}
