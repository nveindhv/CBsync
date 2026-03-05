<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ErpFindProducts extends Command
{
    protected $signature = 'erp:find:products
        {--prefix= : Single prefix to search}
        {--prefixes= : Comma separated prefixes}
        {--take=15 : Stop after this many matches}
        {--page-size=200 : ERP page size}
        {--offset=0 : Start offset}';

    protected $description = 'Scan ERP products until N matches are found for given prefixes.';

    public function handle(): int
    {
        $prefix = (string) $this->option('prefix');
        $prefixes = (string) $this->option('prefixes');

        $list = [];
        if (trim($prefix) !== '') $list[] = trim($prefix);
        if (trim($prefixes) !== '') {
            foreach (explode(',', $prefixes) as $p) {
                $p = trim($p);
                if ($p !== '') $list[] = $p;
            }
        }
        $list = array_values(array_unique($list));

        if (empty($list)) {
            $this->error('Provide --prefix or --prefixes');
            return 1;
        }

        $take = max(1, (int) $this->option('take'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $offset = max(0, (int) $this->option('offset'));

        $erpBase = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpPath = trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = (string) env('ERP_ADMIN', '01');

        if ($erpBase === '' || $erpPath === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return 1;
        }

        $url = $erpBase . '/' . $erpPath . '/' . $erpAdmin . '/products';

        $user = (string) env('ERP_USER', '');
        $pass = (string) env('ERP_PASS', '');

        $http = Http::timeout(60)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->when($user !== '' || $pass !== '', fn($h) => $h->withBasicAuth($user, $pass));

        $fields = ['productCode','externalProductCode','searchName','description','searchKeys'];

        $matches = [];
        $page = 0;

        while (count($matches) < $take) {
            $pageOffset = $offset + ($page * $pageSize);
            $this->line("[ERP_PAGE] offset={$pageOffset} limit={$pageSize}");

            $res = $http->get($url, ['offset' => $pageOffset, 'limit' => $pageSize]);

            if (!$res->ok()) {
                $this->error('ERP request failed: HTTP ' . $res->status() . ' body=' . substr($res->body(), 0, 300));
                break;
            }

            $items = $res->json();
            if (!is_array($items) || count($items) === 0) break;

            foreach ($items as $p) {
                if (!is_array($p)) continue;

                foreach ($fields as $f) {
                    if (!array_key_exists($f, $p)) continue;
                    $val = (string) $p[$f];

                    foreach ($list as $pref) {
                        if ($pref !== '' && stripos($val, $pref) !== false) {
                            $this->info("MATCH prefix={$pref} field={$f} productCode=" . ($p['productCode'] ?? ''));
                            $matches[] = $p;
                            if (count($matches) >= $take) break 3;
                        }
                    }
                }
            }

            $page++;
        }

        $dir = storage_path('app/erp_dump');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $file = $dir . '/prefix_matches_' . time() . '.json';
        file_put_contents($file, json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Saved ' . count($matches) . ' matches to: ' . $file);
        return 0;
    }
}
