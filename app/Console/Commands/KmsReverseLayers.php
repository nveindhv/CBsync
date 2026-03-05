<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Services\Kms\KmsClient;

class KmsReverseLayers extends Command
{
    protected $signature = 'kms:reverse:layers
        {variant : Full variant articleNumber (e.g. 502060375398540)}
        {--ean= : Optional EAN to verify/search}
        {--prefix-min=4 : Minimum prefix length to test}
        {--prefix-max=14 : Maximum prefix length to test (must be < variant length)}
        {--family-len=11 : Suspected family/type_number length (for reporting only)}
        {--variants= : Optional comma-separated list of variants (to compute common prefix)}
        {--limit=5 : getProducts limit per request}
        {--debug : Dump payloads and snapshots}
    ';

    protected $description = 'Reverse engineer KMS product "layers" by probing which articleNumber prefixes exist as real KMS products (base/parent vs variants).';

    public function handle(): int
    {
        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $variant = (string) $this->argument('variant');
        $ean     = $this->option('ean') ? (string)$this->option('ean') : null;

        $min = (int)$this->option('prefix-min');
        $max = (int)$this->option('prefix-max');
        $limit = (int)$this->option('limit');
        $familyLen = (int)$this->option('family-len');

        if ($min < 1) $min = 1;
        if ($max < $min) $max = $min;

        $len = strlen($variant);
        if ($max >= $len) $max = $len - 1;

        $this->line('=== KMS LAYER REVERSE ENGINEER ===');
        $this->line("Variant: {$variant} (len={$len})");
        if ($ean) $this->line("EAN    : {$ean}");
        $this->line("Probe  : prefix {$min}..{$max}, limit={$limit}");
        $this->line("Hint   : family/type_number length={$familyLen}");
        $this->newLine();

        // 1) Baseline: can we fetch the full variant?
        $this->section('1) Full variant lookup');
        $variantRes = $this->getProducts($kms, [
            'offset' => 0,
            'limit' => $limit,
            'articleNumber' => $variant,
        ], $this->option('debug'));
        $this->printCountAndSample($variantRes);

        if ($ean) {
            $this->section('1b) EAN lookup');
            $eanRes = $this->getProducts($kms, [
                'offset' => 0,
                'limit' => $limit,
                'ean' => $ean,
            ], $this->option('debug'));
            $this->printCountAndSample($eanRes);
        }

        // 2) Optional: common prefix from a list of variants
        $variantsOpt = $this->option('variants') ? (string)$this->option('variants') : '';
        $variants = [];
        if (trim($variantsOpt) !== '') {
            $variants = array_values(array_filter(array_map('trim', explode(',', $variantsOpt))));
        }
        if (count($variants) > 1) {
            $this->section('2) Common prefix from provided variants');
            $cp = $this->commonPrefix($variants);
            $this->line("Provided variants: ".count($variants));
            $this->line("Common prefix     : {$cp} (len=".strlen($cp).")");
            $this->newLine();
        }

        // 3) Prefix probing (this is the actual "layer" discovery)
        $this->section('3) Prefix existence probe (base product candidates)');
        $candidates = [];
        for ($L = $min; $L <= $max; $L++) {
            $prefix = substr($variant, 0, $L);

            $res = $this->getProducts($kms, [
                'offset' => 0,
                'limit' => $limit,
                'articleNumber' => $prefix,
            ], $this->option('debug'));

            $count = is_array($res) ? count($res) : 0;

            if ($count > 0) {
                $first = reset($res);
                $name  = is_array($first) && isset($first['name']) ? (string)$first['name'] : '';
                $brand = is_array($first) && isset($first['brand']) ? (string)$first['brand'] : '';
                $unit  = is_array($first) && isset($first['unit']) ? (string)$first['unit'] : '';
                $this->info(sprintf("L=%-2d prefix=%s  FOUND count=%d  brand=%s unit=%s name=%s", $L, $prefix, $count, $brand, $unit, $this->short($name)));
                $candidates[] = ['len' => $L, 'prefix' => $prefix, 'count' => $count, 'sample' => $first];
            } else {
                $this->line(sprintf("L=%-2d prefix=%s  not found", $L, $prefix));
            }
        }

        $this->newLine();
        $this->section('4) Interpretation');
        if (count($candidates) === 0) {
            $this->warn('No shorter prefix exists as a standalone KMS product (for this variant).');
            $this->line('This usually means: this supplier/range stores only the full variant as a product, and "family" is *not* a product row.');
            $this->line('In that case, family linkage is likely via type_number/type_name (needed for createUpdate), not via getProducts(articleNumber=family).');
            return self::SUCCESS;
        }

        // Heuristic: shortest existing prefix is "highest" parent layer (base product)
        usort($candidates, fn($a,$b) => $a['len'] <=> $b['len']);
        $best = $candidates[0];

        $this->info("Most likely BASE layer: prefix={$best['prefix']} (len={$best['len']})");
        $this->line('Why: it exists as its own product, and it is the shortest existing prefix among tested lengths.');
        $this->newLine();

        $this->line('Next practical tests (manual):');
        $this->line('A) Open KMS UI and search for the BASE articleNumber above; verify it shows a "product" with an "Artikelen" tab containing variants.');
        $this->line('B) Run kms:reverse:fields on BASE vs on VARIANT to see which fields are accepted at each layer.');
        $this->line('C) If BASE exists, try createUpdate on BASE with a safe field change (e.g. name suffix) and verify in UI which rows changed (base only vs all variants).');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line($title);
        $this->line(str_repeat('-', strlen($title)));
    }

    private function short(string $s, int $max=60): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (Str::length($s) <= $max) return $s;
        return Str::substr($s, 0, $max-1).'…';
    }

    private function getProducts(KmsClient $kms, array $payload, bool $debug)
    {
        $endpoint = 'kms/product/getProducts';
        if ($debug) {
            $this->line("POST {$endpoint}");
            $this->line('PAYLOAD='.json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        try {
            $res = $kms->post($endpoint, $payload);
            return is_array($res) ? $res : [];
        } catch (\Throwable $e) {
            $this->error('KMS request failed: '.$e->getMessage());
            return [];
        }
    }

    private function printCountAndSample(array $res): void
    {
        $count = count($res);
        $this->line("COUNT={$count}");
        if ($count === 0) {
            $this->line('[]');
            $this->newLine();
            return;
        }
        $firstKey = array_key_first($res);
        $first = $res[$firstKey];
        $this->line('SAMPLE_KEY='.$firstKey);
        $this->line(json_encode($first, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $this->newLine();
    }

    private function commonPrefix(array $strings): string
    {
        if (count($strings) === 0) return '';
        $prefix = (string)$strings[0];
        foreach ($strings as $s) {
            $s = (string)$s;
            $i = 0;
            $max = min(strlen($prefix), strlen($s));
            while ($i < $max && $prefix[$i] === $s[$i]) $i++;
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') break;
        }
        return $prefix;
    }
}
