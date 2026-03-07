<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsMapErpFamilies extends Command
{
    protected $signature = 'kms:map:erp-families {--family=} {--dump-json} {--debug}';
    protected $description = 'Map ERP family buckets against combined KMS scan data.';

    public function handle(): int
    {
        $erp = json_decode(file_get_contents(StoragePathResolver::resolve('private/erp_dump/combined_family_index.json')), true);
        $kms = json_decode(file_get_contents(StoragePathResolver::resolve('private/kms_scan/combined_products_index.json')), true);

        $filter = (string) $this->option('family');
        $rows = [];

        foreach ($erp as $family) {
            $familyNo = (string) ($family['family'] ?? '');
            if ($filter !== '' && $familyNo !== $filter) {
                continue;
            }
            $kmsArticles = $kms['by_prefix'][$familyNo] ?? [];
            $erpCount = (int) ($family['article_count'] ?? count($family['articles'] ?? []));
            $kmsCount = count($kmsArticles);

            $rows[] = [
                'family' => $familyNo,
                'erp_variant_count' => $erpCount,
                'kms_variant_count' => $kmsCount,
                'kms_match_mode' => $kmsCount === 0 ? 'none' : ($kmsCount >= $erpCount ? 'full_or_over' : 'partial'),
                'suspected_parent_mode' => $kmsCount > 0 ? 'variant_anchor_exists' : 'short_parent_missing',
                'recommended_action' => $kmsCount > 0 ? 'build_parent_from_known_data' : 'bootstrap_parent',
                'top_name' => array_key_first($family['names'] ?? []),
            ];
        }

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $path = $outDir . DIRECTORY_SEPARATOR . 'erp_kms_family_map.json';
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        $this->line('JSON: ' . $path);

        return self::SUCCESS;
    }
}
