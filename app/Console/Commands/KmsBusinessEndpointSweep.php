<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class KmsBusinessEndpointSweep extends Command
{
    protected $signature = 'kms:business:endpoint-sweep
        {referenceId : Bijvoorbeeld 00001}
        {--name= : Naam voor create/update test}
        {--short-name= : ShortName voor create/update test}
        {--debug : Verbose output}
        {--write-json : Schrijf rapport naar storage/app/private/kms_customer_sync}';

    protected $description = 'Probeert meerdere KMS business endpoint- en auth-varianten voor list en createUpdate.';

    public function handle(): int
    {
        $referenceId = (string) $this->argument('referenceId');
        $name = (string) ($this->option('name') ?: $referenceId);
        $shortName = (string) ($this->option('short-name') ?: substr($name, 0, 32));
        $debug = (bool) $this->option('debug');

        [$token, $tokenSource] = $this->obtainKmsToken($debug);
        if ($token === null) {
            $this->error('Could not obtain KMS token');
            return self::FAILURE;
        }

        $payload = [
            'referenceId' => $referenceId,
            'name' => $name,
            'shortName' => $shortName,
        ];

        $bases = $this->candidateBases();
        $listEndpoints = ['kms/business/list', 'business/list'];
        $writeEndpoints = ['kms/business/createUpdate', 'business/createUpdate'];

        $report = [
            'referenceId' => $referenceId,
            'payload' => $payload,
            'token_source' => $tokenSource,
            'generated_at' => now()->toIso8601String(),
            'bases' => $bases,
            'list' => [],
            'write' => [],
        ];

        $this->line('=== KMS BUSINESS ENDPOINT SWEEP ===');
        $this->line('referenceId : ' . $referenceId);
        $this->line('token source: ' . $tokenSource);

        foreach ($bases as $base) {
            foreach ($listEndpoints as $endpoint) {
                $result = $this->postVariants($base, $endpoint, $token, [
                    'referenceId' => $referenceId,
                    'offset' => 0,
                    'limit' => 10,
                ], $debug);
                $result['base'] = $base;
                $result['endpoint'] = $endpoint;
                $report['list'][] = $result;
            }

            foreach ($writeEndpoints as $endpoint) {
                $result = $this->postVariants($base, $endpoint, $token, $payload, $debug);
                $result['base'] = $base;
                $result['endpoint'] = $endpoint;
                $report['write'][] = $result;
            }
        }

        $listSuccess = collect($report['list'])->contains(function ($r) {
            foreach (($r['variants'] ?? []) as $v) {
                $status = (int) ($v['http_status'] ?? 0);
                if ($status >= 200 && $status < 300) {
                    return true;
                }
            }
            return false;
        });
        $writeSuccess = collect($report['write'])->contains(function ($r) {
            foreach (($r['variants'] ?? []) as $v) {
                $status = (int) ($v['http_status'] ?? 0);
                if ($status >= 200 && $status < 300) {
                    return true;
                }
            }
            return false;
        });

        $summary = [
            'list_any_success' => $listSuccess,
            'write_any_success' => $writeSuccess,
        ];
        $report['summary'] = $summary;

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_customer_sync');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $path = $dir . DIRECTORY_SEPARATOR . 'business_endpoint_sweep_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $referenceId) . '_' . now()->format('Ymd_His') . '.json';
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('REPORT JSON : ' . $path);
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function candidateBases(): array
    {
        $configured = $this->firstEnv(['KMS_REST_BASE_URL','KMS_API_BASE_URL','KMS_BASE_URL']);

        $bases = array_filter(array_unique([
            $configured,
            'https://www.twensokms.nl/rest/democomfortbest',
            'https://www.twensokms.nl/rest',
            'https://www.twensokms.nl/democomfortbest',
        ]));

        return array_values($bases);
    }

    private function postVariants(string $base, string $endpoint, string $token, array $payload, bool $debug = false): array
    {
        $variants = [
            'header_access_token' => fn($req) => $req->withHeaders(['access_token' => $token, 'Content-Type' => 'application/json']),
            'bearer_token' => fn($req) => $req->withToken($token)->withHeaders(['Content-Type' => 'application/json']),
            'header_authorization_plain' => fn($req) => $req->withHeaders(['Authorization' => $token, 'Content-Type' => 'application/json']),
        ];

        $out = [
            'variants' => [],
            'best_http_status' => 0,
        ];

        foreach ($variants as $label => $apply) {
            $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
            try {
                $req = Http::timeout(45)->acceptJson()->withoutVerifying();
                $req = $apply($req);
                $resp = $req->post($url, $payload);
                $body = $resp->json();
                if ($body === null) {
                    $body = $resp->body();
                }
                $item = [
                    'label' => $label,
                    'url' => $url,
                    'http_status' => $resp->status(),
                    'body' => $body,
                ];
                $out['variants'][] = $item;
                $out['best_http_status'] = max($out['best_http_status'], (int) $resp->status());
                if ($debug) {
                    $this->line('[' . $label . '] ' . $url . ' => ' . $resp->status());
                }
            } catch (\Throwable $e) {
                $item = [
                    'label' => $label,
                    'url' => $url,
                    'http_status' => 0,
                    'body' => ['exception' => $e->getMessage()],
                ];
                $out['variants'][] = $item;
                if ($debug) {
                    $this->line('[' . $label . '] ' . $url . ' => EXCEPTION');
                }
            }
        }

        return $out;
    }

    private function obtainKmsToken(bool $debug = false): array
    {
        $clientId = $this->firstEnv(['KMS_CLIENT_ID','CLIENT_ID']);
        $clientSecret = $this->firstEnv(['KMS_CLIENT_SECRET','CLIENT_SECRET']);
        $username = $this->firstEnv(['KMS_USERNAME','KMS_USER','KMS_LOGIN']);
        $password = $this->firstEnv(['KMS_PASSWORD','KMS_PASS']);
        $namespace = $this->firstEnv(['KMS_NAMESPACE','KMS_API_NAMESPACE','KMS_REST_NAMESPACE'], 'democomfortbest');
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
