<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Kms\KmsClient;

class KmsUpdatePriceSmart extends Command
{
    protected $signature = 'kms:update:price-smart
        {article : Article number}
        {price : New sales price}
        {--ean= : EAN (recommended if article is not unique)}
        {--type-len=11 : Length of family/type prefix derived from article number}
        {--type-number= : Override type_number}
        {--type-name= : Override type_name}
        {--no-type : Do not send type_number/type_name}
        {--force-full : Skip minimal attempt; send full matrix payload immediately}
        {--dry-run : Do not call createUpdate (still builds payload + verifies before/after)}
        {--debug : Verbose output}';

    protected $description = 'Update KMS price reliably: try minimal payload first; if ignored, retry with full matrix payload (type + variant fields) and verify after.';

    public function handle(KmsClient $kms): int
    {
        $article  = (string) $this->argument('article');
        $newPrice = (float) $this->argument('price');
        $eanOpt   = $this->option('ean');
        $debug    = (bool) $this->option('debug');
        $dryRun   = (bool) $this->option('dry-run');
        $forceFull = (bool) $this->option('force-full');

        // Fetch current product(s) from KMS (articleNumber first; fallback to ean if needed)
        $before = $kms->post('kms/product/getProducts', array_filter([
            'offset' => 0,
            'limit' => 25,
            'articleNumber' => $article,
            'ean' => $eanOpt ?: null,
        ], fn($v) => $v !== null && $v !== ''));

        $beforeCount = is_array($before) ? count($before) : 0;
        $this->line('[BEFORE_COUNT] ' . $beforeCount);
        if ($debug) {
            $this->line(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $first = null;
        if (is_array($before) && $beforeCount > 0) {
            // Take the first element (KMS uses id as key)
            $first = reset($before);
        }

        // Determine EAN (required)
        $ean = $eanOpt ?: ($first['ean'] ?? null);
        if (!$ean) {
            $this->error('EAN missing. Provide --ean=...');
            return self::FAILURE;
        }

        // Helper to verify after
        $verify = function () use ($kms, $article, $ean, $debug): array {
            $after = $kms->post('kms/product/getProducts', array_filter([
                'offset' => 0,
                'limit' => 25,
                'articleNumber' => $article,
                'ean' => $ean,
            ], fn($v) => $v !== null && $v !== ''));

            $afterCount = is_array($after) ? count($after) : 0;
            $this->line('[AFTER_COUNT] ' . $afterCount);
            if ($debug) {
                $this->line(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $afterFirst = (is_array($after) && $afterCount > 0) ? reset($after) : null;
            return [$after, $afterFirst];
        };

        $attempt = function (array $product, string $label) use ($kms, $dryRun): array {
            $payload = ['products' => [$product]];
            $this->line("[PAYLOAD:$label] " . json_encode($payload, JSON_UNESCAPED_SLASHES));

            if ($dryRun) {
                $this->line("[CREATEUPDATE_RESPONSE:$label] DRY-RUN");
                return [true, ['success' => true, 'dry_run' => true]];
            }

            $resp = $kms->post('kms/product/createUpdate', $payload);
            $this->line("[CREATEUPDATE_RESPONSE:$label] " . json_encode($resp, JSON_UNESCAPED_SLASHES));
            return [true, $resp];
        };

        // Attempt 1: minimal payload (the thing that often returns success=true but is ignored)
        if (!$forceFull) {
            $minimal = array_filter([
                'article_number' => $article,
                'articleNumber'  => $article,
                'ean'            => (string) $ean,
                'price'          => $newPrice,
            ], fn($v) => $v !== null && $v !== '');

            $this->line('--- Attempt 1: MINIMAL ---');
            $attempt($minimal, 'MINIMAL');

            [$after, $afterFirst] = $verify();
            if (!$afterFirst) {
                $this->error('After verify: product not found.');
                return self::FAILURE;
            }

            $afterPrice = $afterFirst['price'] ?? null;
            if ((string) $afterPrice === (string) $newPrice || abs((float) $afterPrice - (float) $newPrice) < 0.0001) {
                $this->info('UPDATED ✔ price=' . $afterPrice . ' (minimal payload)');
                return self::SUCCESS;
            }

            $this->warn('IGNORED ✖ minimal payload (price stayed ' . json_encode($afterPrice) . ')');
        }

        // Attempt 2: full matrix payload
        $this->line('--- Attempt 2: FULL_MATRIX ---');

        $unit  = $first['unit']  ?? null;
        $brand = $first['brand'] ?? null;
        $color = $first['color'] ?? null;
        $size  = $first['size']  ?? null;

        $typeLen = (int) $this->option('type-len');
        $typeNumber = $this->option('type-number') ?: (ctype_digit($article) && $typeLen > 0 ? substr($article, 0, $typeLen) : null);
        $typeName = $this->option('type-name') ?: ($typeNumber ? ('FAMILY ' . $typeNumber) : null);

        $full = array_filter([
            'article_number' => $article,
            'articleNumber'  => $article,
            'ean'            => (string) $ean,
            'price'          => $newPrice,
            'unit'           => $unit,
            'brand'          => $brand,
            'color'          => $color,
            'size'           => $size,
            'type_number'    => $this->option('no-type') ? null : $typeNumber,
            'type_name'      => $this->option('no-type') ? null : $typeName,
        ], fn($v) => $v !== null && $v !== '');

        $attempt($full, 'FULL');

        [$after, $afterFirst] = $verify();
        if (!$afterFirst) {
            $this->error('After verify: product not found.');
            return self::FAILURE;
        }

        $afterPrice = $afterFirst['price'] ?? null;
        if ((string) $afterPrice === (string) $newPrice || abs((float) $afterPrice - (float) $newPrice) < 0.0001) {
            $this->info('UPDATED ✔ price=' . $afterPrice . ' (full payload)');
            return self::SUCCESS;
        }

        $this->warn('NO CHANGE ✖ price=' . json_encode($afterPrice));
        return self::FAILURE;
    }
}
