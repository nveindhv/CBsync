<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsBuildParentPayload extends Command
{
    protected $signature = 'kms:build:parent-payload {family} {--dump-json} {--debug}';
    protected $description = 'Build a candidate KMS parent payload from ERP family and KMS scan context.';

    public function handle(): int
    {
        $familyNo = (string) $this->argument('family');
        $erpFamilies = json_decode(file_get_contents(StoragePathResolver::resolve('private/erp_dump/combined_family_index.json')), true);
        $kmsIndex = json_decode(file_get_contents(StoragePathResolver::resolve('private/kms_scan/combined_products_index.json')), true);

        $family = collect($erpFamilies)->firstWhere('family', $familyNo);
        if (! $family) {
            $this->error('ERP family not found: ' . $familyNo);
            return self::FAILURE;
        }

        $kmsArticles = $kmsIndex['by_prefix'][$familyNo] ?? [];
        $seedArticle = $kmsArticles[0] ?? ($family['articles'][0] ?? null);
        $seedRow = $seedArticle && isset($kmsIndex['by_article'][$seedArticle]) ? $kmsIndex['by_article'][$seedArticle] : null;

        $name = (string) (array_key_first($family['names'] ?? []) ?? ($seedRow['name'] ?? ''));
        $brand = (string) ($seedRow['brand'] ?? '');
        $unit = (string) ($seedRow['unit'] ?? 'STK');

        $payload = [
            'inference_basis' => array_values(array_slice($kmsArticles ?: ($family['articles'] ?? []), 0, 5)),
            'suspected_family_number' => $familyNo,
            'candidate_parent_payload' => [
                'products' => [[
                    'article_number' => $familyNo,
                    'articleNumber' => $familyNo,
                    'name' => $name,
                    'brand' => $brand,
                    'unit' => $unit,
                    'type_number' => $familyNo,
                    'typeNumber' => $familyNo,
                    'type_name' => '',
                    'typeName' => '',
                ]],
            ],
            'variant_specific_fields_to_strip' => [
                'credit','defaultColor','color','size','hasImages','imageProductId','freeStockInfo','deliveryDateInfo','weight'
            ],
            'kms_seed_article' => $seedArticle,
        ];

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $path = $outDir . DIRECTORY_SEPARATOR . 'parent_payload_' . $familyNo . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('JSON: ' . $path);

        return self::SUCCESS;
    }
}
