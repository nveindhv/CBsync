<?php

namespace App\Console\Commands;

use App\Services\KMS\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsInferParentFromVariant extends Command
{
    protected $signature = 'kms:infer:parent-from-variant
        {variant : Existing KMS variant article number}
        {--ean= : Optional EAN for the variant}
        {--siblings= : Comma-separated sibling article numbers already known}
        {--dump-json : Save inferred parent recipe to storage}
        {--debug : Print payloads}';

    protected $description = 'Infer a possible parent/family payload from one known variant and optional siblings.';

    public function handle(KmsClient $kms): int
    {
        $variant = trim((string) $this->argument('variant'));
        $ean = trim((string) $this->option('ean'));
        $siblingsOpt = trim((string) $this->option('siblings'));
        $dumpJson = (bool) $this->option('dump-json');
        $debug = (bool) $this->option('debug');

        $articles = array_values(array_unique(array_filter(array_merge([
            $variant,
        ], array_map('trim', $siblingsOpt !== '' ? explode(',', $siblingsOpt) : [])))));

        $samples = [];
        foreach ($articles as $article) {
            $rows = $this->fetch($kms, ['offset' => 0, 'limit' => 5, 'articleNumber' => $article], $debug);
            if (isset($rows[0]) && is_array($rows[0])) {
                $samples[$article] = $rows[0];
            }
        }

        if ($samples === [] && $ean !== '') {
            $rows = $this->fetch($kms, ['offset' => 0, 'limit' => 5, 'ean' => $ean], $debug);
            if (isset($rows[0]) && is_array($rows[0])) {
                $samples[$variant] = $rows[0];
            }
        }

        if ($samples === []) {
            $this->error('Could not fetch any source variants from KMS.');
            return self::FAILURE;
        }

        $common = $this->commonFields(array_values($samples));
        $variantSample = $samples[array_key_first($samples)] ?? [];
        $article = $this->text($variantSample['article_number'] ?? $variantSample['articleNumber'] ?? $variant);
        $familyNumber = $this->text($variantSample['type_number'] ?? $variantSample['typeNumber'] ?? substr($article, 0, 9));
        $familyName = $this->text($variantSample['type_name'] ?? $variantSample['typeName'] ?? '');

        $recipe = [
            'inference_basis' => array_keys($samples),
            'suspected_family_number' => $familyNumber,
            'suspected_family_name' => $familyName,
            'candidate_parent_payload' => [
                'products' => [[
                    'article_number' => $familyNumber !== '' ? $familyNumber : substr($article, 0, 9),
                    'articleNumber' => $familyNumber !== '' ? $familyNumber : substr($article, 0, 9),
                    'name' => $familyName !== '' ? $familyName : ($this->text($common['name'] ?? $common['description'] ?? '') ?: $this->text($variantSample['name'] ?? '')),
                    'brand' => $this->text($common['brand'] ?? $variantSample['brand'] ?? ''),
                    'unit' => $this->text($common['unit'] ?? $variantSample['unit'] ?? ''),
                    'type_number' => $familyNumber,
                    'typeNumber' => $familyNumber,
                    'type_name' => $familyName,
                    'typeName' => $familyName,
                ]],
            ],
            'common_fields_across_variants' => $common,
            'variant_specific_fields_to_strip' => $this->variantSpecificKeys(array_values($samples), array_keys($common)),
        ];

        $this->line('=== INFERRED PARENT RECIPE ===');
        $this->line(json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($dumpJson) {
            Storage::makeDirectory('kms_scan');
            $path = 'kms_scan/inferred_parent_' . now()->format('Ymd_His') . '.json';
            Storage::put($path, json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: ' . storage_path('app/' . $path));
        }

        return self::SUCCESS;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetch(KmsClient $kms, array $payload, bool $debug): array
    {
        if ($debug) {
            $this->line('POST kms/product/getProducts');
            $this->line('PAYLOAD=' . json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        try {
            $raw = $kms->post('kms/product/getProducts', $payload);
        } catch (\Throwable $e) {
            $this->error('KMS request failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, 'is_array'));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function commonFields(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys = array_values(array_unique(array_merge($keys, array_keys($row))));
        }

        $common = [];
        foreach ($keys as $key) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = $row[$key] ?? null;
            }
            $first = $values[0] ?? null;
            $allSame = true;
            foreach ($values as $value) {
                if ($value !== $first) {
                    $allSame = false;
                    break;
                }
            }
            if ($allSame && $first !== null && !in_array($key, ['article_number', 'articleNumber', 'ean', 'size', 'color'], true)) {
                $common[$key] = $first;
            }
        }

        return $common;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $commonKeys @return array<int,string> */
    private function variantSpecificKeys(array $rows, array $commonKeys): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys = array_values(array_unique(array_merge($keys, array_keys($row))));
        }
        return array_values(array_diff($keys, $commonKeys, ['article_number', 'articleNumber', 'ean']));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
