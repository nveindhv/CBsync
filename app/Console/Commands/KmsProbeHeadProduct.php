<?php

namespace App\Console\Commands;

use App\Services\KMS\KmsClient;
use Illuminate\Console\Command;

class KmsProbeHeadProduct extends Command
{
    protected $signature = 'kms:probe:head-product
        {head : Suspected head/base article number}
        {--variant= : Known child variant to compare with}
        {--ean= : Optional EAN lookup for the head/base}
        {--debug : Print payloads}';

    protected $description = 'Compare a suspected head/base KMS product with a known variant.';

    public function handle(KmsClient $kms): int
    {
        $head = trim((string) $this->argument('head'));
        $variant = trim((string) $this->option('variant'));
        $ean = trim((string) $this->option('ean'));
        $debug = (bool) $this->option('debug');

        $this->line('=== KMS HEAD PRODUCT PROBE ===');
        $this->line('Head    : ' . $head);
        if ($variant !== '') {
            $this->line('Variant : ' . $variant);
        }
        if ($ean !== '') {
            $this->line('EAN     : ' . $ean);
        }
        $this->newLine();

        $headRows = $this->fetch($kms, ['offset' => 0, 'limit' => 10, 'articleNumber' => $head], $debug);
        $this->line('Head COUNT=' . count($headRows));
        $headSample = $headRows[0] ?? null;
        if (is_array($headSample)) {
            $this->line(json_encode($headSample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->newLine();

        if ($ean !== '') {
            $eanRows = $this->fetch($kms, ['offset' => 0, 'limit' => 10, 'ean' => $ean], $debug);
            $this->line('Head EAN COUNT=' . count($eanRows));
            $this->newLine();
        }

        if ($variant !== '') {
            $variantRows = $this->fetch($kms, ['offset' => 0, 'limit' => 10, 'articleNumber' => $variant], $debug);
            $this->line('Variant COUNT=' . count($variantRows));
            $variantSample = $variantRows[0] ?? null;
            if (is_array($variantSample)) {
                $this->line(json_encode($variantSample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $this->newLine();

            if (is_array($headSample) && is_array($variantSample)) {
                $this->compareSamples($headSample, $variantSample);
            }
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

        $assoc = array_keys($raw) !== range(0, count($raw) - 1);
        if (!$assoc) {
            return array_values(array_filter($raw, 'is_array'));
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /** @param array<string,mixed> $head @param array<string,mixed> $variant */
    private function compareSamples(array $head, array $variant): void
    {
        $this->line('Comparison');
        $this->line('----------');

        $interesting = [
            'article_number', 'articleNumber', 'ean', 'name', 'brand', 'unit', 'type_number', 'typeNumber', 'type_name', 'typeName', 'size', 'color', 'purchase_price', 'purchasePrice',
        ];

        foreach ($interesting as $key) {
            $headValue = $head[$key] ?? null;
            $variantValue = $variant[$key] ?? null;
            if ($headValue === null && $variantValue === null) {
                continue;
            }
            $this->line(sprintf('%s => head=%s | variant=%s', $key, $this->stringify($headValue), $this->stringify($variantValue)));
        }
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '(complex)';
    }
}
