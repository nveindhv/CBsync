<?php

namespace App\Services\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $retryCount = 2,
        private readonly int $retryBaseSleepMs = 300,
        private readonly bool $verifySsl = true
    ) {}

    public function requestJson(
        string $method,
        string $url,
        array $headers = [],
        array $jsonBody = [],
        array $query = [],
        ?string $correlationId = null
    ): Response {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $fullUrl = $this->withQuery($url, $query);
        $sanitizedUrl = $this->sanitizeUrl($fullUrl);

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryCount; $attempt++) {
            $start = microtime(true);

            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withOptions(['verify' => $this->verifySsl])
                    ->withHeaders($headers)
                    ->acceptJson()
                    ->asJson()
                    ->send($method, $fullUrl, [
                        'json' => $jsonBody,
                    ]);

                $durationMs = (int) round((microtime(true) - $start) * 1000);

                logger()->info('[HTTP_JSON]', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'url' => $sanitizedUrl,
                    'status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'attempt' => $attempt,
                    'attempts' => $this->retryCount,
                ]);

                if ($response->successful()) {
                    return $response;
                }

                if ($this->isRetryableStatus($response->status()) && $attempt < $this->retryCount) {
                    usleep($this->backoffMs($attempt) * 1000);
                    continue;
                }

                $snippet = mb_substr((string) $response->body(), 0, 800);
                throw new RuntimeException(
                    "HTTP error: status={$response->status()} method={$method} url={$sanitizedUrl} correlation_id={$correlationId} body_snippet={$snippet}"
                );
            } catch (\Throwable $e) {
                $lastException = $e;

                $durationMs = (int) round((microtime(true) - $start) * 1000);

                logger()->warning('[HTTP_JSON_EXCEPTION]', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'url' => $sanitizedUrl,
                    'duration_ms' => $durationMs,
                    'attempt' => $attempt,
                    'attempts' => $this->retryCount,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->retryCount) {
                    usleep($this->backoffMs($attempt) * 1000);
                    continue;
                }

                throw new RuntimeException(
                    $this->buildExceptionMessage($correlationId, $method, $sanitizedUrl, $e),
                    0,
                    $e
                );
            }
        }

        throw new RuntimeException("HTTP request failed unexpectedly. correlation_id={$correlationId}", 0, $lastException);
    }

    /**
     * FORM (application/x-www-form-urlencoded) request helper.
     *
     * Guzzle vereist: form_params (niet body).
     */
    public function requestForm(
        string $method,
        string $url,
        array $headers = [],
        array $formBody = [],
        array $query = [],
        ?string $correlationId = null
    ): Response {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $fullUrl = $this->withQuery($url, $query);
        $sanitizedUrl = $this->sanitizeUrl($fullUrl);

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryCount; $attempt++) {
            $start = microtime(true);

            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withOptions(['verify' => $this->verifySsl])
                    ->withHeaders($headers)
                    ->asForm()
                    ->send($method, $fullUrl, [
                        'form_params' => $formBody,
                    ]);

                $durationMs = (int) round((microtime(true) - $start) * 1000);

                logger()->info('[HTTP_FORM]', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'url' => $sanitizedUrl,
                    'status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'attempt' => $attempt,
                    'attempts' => $this->retryCount,
                ]);

                if ($response->successful()) {
                    return $response;
                }

                if ($this->isRetryableStatus($response->status()) && $attempt < $this->retryCount) {
                    usleep($this->backoffMs($attempt) * 1000);
                    continue;
                }

                $snippet = mb_substr((string) $response->body(), 0, 800);
                throw new RuntimeException(
                    "HTTP error: status={$response->status()} method={$method} url={$sanitizedUrl} correlation_id={$correlationId} body_snippet={$snippet}"
                );
            } catch (\Throwable $e) {
                $lastException = $e;

                $durationMs = (int) round((microtime(true) - $start) * 1000);

                logger()->warning('[HTTP_FORM_EXCEPTION]', [
                    'correlation_id' => $correlationId,
                    'method' => $method,
                    'url' => $sanitizedUrl,
                    'duration_ms' => $durationMs,
                    'attempt' => $attempt,
                    'attempts' => $this->retryCount,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->retryCount) {
                    usleep($this->backoffMs($attempt) * 1000);
                    continue;
                }

                throw new RuntimeException(
                    $this->buildExceptionMessage($correlationId, $method, $sanitizedUrl, $e),
                    0,
                    $e
                );
            }
        }

        throw new RuntimeException("HTTP request failed unexpectedly. correlation_id={$correlationId}", 0, $lastException);
    }

    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function backoffMs(int $attempt): int
    {
        return $this->retryBaseSleepMs * max(1, $attempt);
    }

    private function withQuery(string $url, array $query): string
    {
        if (empty($query)) return $url;
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($query);
    }

    private function sanitizeUrl(string $url): string
    {
        return preg_replace('/(access_token=)[^&]+/i', '$1***', $url) ?? $url;
    }

    private function buildExceptionMessage(string $correlationId, string $method, string $sanitizedUrl, \Throwable $e): string
    {
        return "HTTP exception: method={$method} url={$sanitizedUrl} correlation_id={$correlationId} error={$e->getMessage()}";
    }
}
