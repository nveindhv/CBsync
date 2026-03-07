<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class KmsProbeVisibleVariantRequirements extends Command
{
    protected $signature = 'kms:probe:visible-variant-requirements
        {article : Test article number to create/update}
        {--ean= : EAN}
        {--brand= : Brand}
        {--unit=STK : Unit}
        {--color= : Color}
        {--size= : Size}
        {--type-number= : Type number}
        {--type-name= : Type name}
        {--name= : Product name}
        {--price=12.34 : Price}
        {--purchase-price=0 : Purchase price}
        {--write-json : Write report JSON}
        {--debug : Show payloads}';

    protected $description = 'Start from a known good visible variant model and reduce fields one-by-one until visibility breaks.';

    public function handle(): int
    {
        if (! class_exists(\App\Services\Kms\KmsClient::class)) {
            $this->error('KmsClient class not found.');
            return self::FAILURE;
        }

        $article = trim((string) $this->argument('article'));
        $baseline = [
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => (string) $this->option('ean'),
            'name' => (string) ($this->option('name') ?: ('TEST ' . $article)),
            'price' => (float) $this->option('price'),
            'purchase_price' => (float) $this->option('purchase-price'),
            'brand' => (string) $this->option('brand'),
            'unit' => (string) $this->option('unit'),
            'color' => (string) $this->option('color'),
            'size' => (string) $this->option('size'),
            'type_number' => (string) $this->option('type-number'),
            'type_name' => (string) $this->option('type-name'),
        ];

        $variants = [
            'baseline_full' => [],
            'drop_type_name' => ['type_name'],
            'drop_type_number' => ['type_number'],
            'drop_size' => ['size'],
            'drop_color' => ['color'],
            'drop_brand' => ['brand'],
            'drop_unit' => ['unit'],
            'drop_ean' => ['ean'],
            'drop_type_pair' => ['type_name', 'type_number'],
            'drop_color_size' => ['color', 'size'],
        ];

        /** @var \App\Services\Kms\KmsClient $kms */
        $kms = app(\App\Services\Kms\KmsClient::class);
        $results = [];

        $this->line('=== KMS VISIBLE VARIANT REQUIREMENTS ===');
        $this->line('Article: ' . $article);

        foreach ($variants as $label => $dropFields) {
            $payloadRow = $baseline;
            foreach ($dropFields as $field) {
                unset($payloadRow[$field]);
            }
            $payloadRow = array_filter($payloadRow, static fn ($v) => $v !== '' && $v !== null);
            $payload = ['products' => [$payloadRow]];

            if ($this->option('debug')) {
                $this->line('--- ' . $label . ' ---');
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $before = $this->lookup($kms, $article, (string) ($payloadRow['ean'] ?? ''));
            $create = $kms->post('kms/product/createUpdate', $payload);
            $after = $this->lookup($kms, $article, (string) ($payloadRow['ean'] ?? ''));

            $visible = ($after['articleCount'] > 0) || ($after['eanCount'] > 0);
            $results[] = [
                'label' => $label,
                'dropped' => $dropFields,
                'createUpdate' => $create,
                'before' => $before,
                'after' => $after,
                'visible' => $visible,
            ];

            $this->line(sprintf('%s => %s', $label, $visible ? 'VISIBLE' : 'NOT_VISIBLE'));
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $path = $dir . '/visible_variant_requirements_' . $article . '.json';
            file_put_contents($path, json_encode([
                'article' => $article,
                'baseline' => $baseline,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('JSON: ' . $path);
        }

        return self::SUCCESS;
    }

    private function lookup($kms, string $article, string $ean): array
    {
        $articleRows = $this->normalizeRows($kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ]));

        $eanRows = [];
        if ($ean !== '') {
            $eanRows = $this->normalizeRows($kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 10,
                'ean' => $ean,
            ]));
        }

        return [
            'articleCount' => count($articleRows),
            'eanCount' => count($eanRows),
            'snapshot' => $articleRows[0] ?? $eanRows[0] ?? null,
        ];
    }

    private function normalizeRows(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return array_values(array_filter($response['data'], 'is_array'));
        }
        if (is_array($response) && array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return [];
    }
}
