<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class KmsBusinessAuthProbe extends Command
{
    protected $signature = 'kms:business:auth-probe
        {referenceId : Bijvoorbeeld 00001}
        {--name= : Naam voor write-payload}
        {--write-json : Schrijf rapport naar storage/app/private/kms_customer_sync}
        {--debug : Verbose logging}';

    protected $description = 'Diagnoseer los van de syncer of kms/business/list en kms/business/createUpdate geautoriseerd zijn.';

    public function handle(): int
    {
        $referenceId = (string) $this->argument('referenceId');
        $name = (string) $this->option('name');
        $debug = (bool) $this->option('debug');

        [$token, $tokenSource] = $this->obtainKmsToken($debug);
        if ($token === null) {
            $this->error('Could not obtain KMS token');
            return self::FAILURE;
        }

        $payload = array_filter([
            'referenceId' => $referenceId,
            'name' => $name,
            'shortName' => $name !== '' ? mb_substr($name, 0, 32) : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $this->line('=== KMS BUSINESS AUTH PROBE ===');
        $this->line('referenceId : ' . $referenceId);
        $this->line('token source: ' . $tokenSource);

        $before = $this->kmsPost($token, 'kms/business/list', [
            'referenceId' => $referenceId,
            'offset' => 0,
            'limit' => 10,
        ]);

        $write = $this->kmsPost($token, 'kms/business/createUpdate', $payload);

        $after = $this->kmsPost($token, 'kms/business/list', [
            'referenceId' => $referenceId,
            'offset' => 0,
            'limit' => 10,
        ]);

        $beforeCount = $this->countRows($before);
        $afterCount = $this->countRows($after);
        $beforeHttp = (int) ($before['http_status'] ?? 200);
        $afterHttp = (int) ($after['http_status'] ?? 200);
        $writeHttp = (int) ($write['http_status'] ?? 200);

        $report = [
            'referenceId' => $referenceId,
            'payload' => $payload,
            'before' => $before,
            'write' => $write,
            'after' => $after,
            'deduction' => [
                'before_http_status' => $beforeHttp,
                'after_http_status' => $afterHttp,
                'write_http_status' => $writeHttp,
                'before_count' => $beforeCount,
                'after_count' => $afterCount,
                'list_works' => $beforeHttp < 400 && $afterHttp < 400,
                'createupdate_works' => (($write['success'] ?? null) === true) && $writeHttp < 400,
                'auth_problem' => $writeHttp === 401 || $beforeHttp === 401 || $afterHttp === 401,
                'conclusion' => $this->deduceConclusion($beforeHttp, $afterHttp, $writeHttp),
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_customer_sync');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'business_auth_probe_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $referenceId) . '_' . now()->format('Ymd_His') . '.json';
            File::put($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('REPORT JSON : ' . $file);
        }

        return self::SUCCESS;
    }

    private function countRows($raw): int
    {
        if (! is_array($raw)) {
            return 0;
        }
        if (isset($raw['http_status']) && (int) $raw['http_status'] >= 400) {
            return 0;
        }
        if (array_values($raw) === $raw) {
            return count(array_filter($raw, 'is_array'));
        }
        foreach (['data', 'rows', 'items', 'results', 'businesses'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return count(array_filter($raw[$key], 'is_array'));
            }
        }
        return 0;
    }

    private function deduceConclusion(int $beforeHttp, int $afterHttp, int $writeHttp): string
    {
        if ($beforeHttp < 400 && $afterHttp < 400 && $writeHttp === 401) {
            return 'List werkt, createUpdate is niet geautoriseerd voor dit token/namespace.';
        }
        if ($beforeHttp === 401 && $afterHttp === 401 && $writeHttp === 401) {
            return 'Zowel list als createUpdate geven authorisatieproblemen; controleer endpoint/namespace/rechten.';
        }
        if ($writeHttp >= 400) {
            return 'createUpdate faalt; controleer rechten, namespace en payload.';
        }
        return 'Geen harde authorisatieblokkade aangetoond in deze probe.';
    }

    private function kmsPost(string $token, string $endpoint, array $payload)
    {
        $namespace = $this->firstEnv(['KMS_NAMESPACE', 'KMS_API_NAMESPACE', 'KMS_REST_NAMESPACE'], 'test');
        $restBase = $this->firstEnv(['KMS_REST_BASE_URL', 'KMS_API_BASE_URL', 'KMS_BASE_URL'], 'https://www.twensokms.nl/rest/' . $namespace);
        $url = rtrim($restBase, '/');

        if (strpos($url, '/rest/') === false) {
            $url .= '/rest/' . $namespace;
        }

        $url .= '/' . ltrim($endpoint, '/');

        $response = Http::timeout(60)
            ->acceptJson()
            ->withoutVerifying()
            ->withHeaders([
                'access_token' => $token,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            return [
                'http_status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ];
        }

        return $response->json() ?: [];
    }

    private function obtainKmsToken(bool $debug = false): array
    {
        $clientId = $this->firstEnv(['KMS_CLIENT_ID', 'CLIENT_ID']);
        $clientSecret = $this->firstEnv(['KMS_CLIENT_SECRET', 'CLIENT_SECRET']);
        $username = $this->firstEnv(['KMS_USERNAME', 'KMS_USER', 'KMS_LOGIN']);
        $password = $this->firstEnv(['KMS_PASSWORD', 'KMS_PASS']);
        $namespace = $this->firstEnv(['KMS_NAMESPACE', 'KMS_API_NAMESPACE', 'KMS_REST_NAMESPACE'], 'test');
        $tokenUrl = $this->firstEnv(['KMS_TOKEN_URL'], 'https://www.twensokms.nl/oauth/' . $namespace . '/v2/token');

        if ($clientId === null || $clientSecret === null || $username === null || $password === null) {
            return [null, 'missing_env'];
        }

        $response = Http::timeout(30)->acceptJson()->withoutVerifying()->get($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]);

        if (! $response->successful()) {
            if ($debug) {
                $this->warn('KMS token http=' . $response->status() . ' body=' . $response->body());
            }
            return [null, 'http_' . $response->status()];
        }

        $json = $response->json() ?: [];
        return [$json['access_token'] ?? null, 'oauth_password'];
    }

    private function firstEnv(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = env($key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }
        return $default;
    }
}
