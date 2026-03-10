<?php

namespace App\Services\Kms;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class KmsBusinessSyncService
{
    public function __construct(
        protected KmsClient $kms,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBusinesses(?string $dateTime = null, int $offset = 0, int $limit = 200, ?string $correlationId = null): array
    {
        $payload = [
            'offset' => $offset,
            'limit' => $limit,
        ];

        if ($dateTime !== null && $dateTime !== '') {
            $payload['dateTime'] = $dateTime;
        }

        $response = $this->kms->post('kms/business/list', $payload, $correlationId);

        return $this->normalizeRows($response);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllBusinesses(?string $dateTime = null, int $pageSize = 200, int $maxPages = 100, bool $debug = false): array
    {
        $all = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $pageSize;
            $rows = $this->listBusinesses($dateTime, $offset, $pageSize, (string) Str::uuid());
            $count = count($rows);

            if ($debug) {
                echo "[KMS_BUSINESS_PAGE] offset={$offset} rows={$count}" . PHP_EOL;
            }

            if ($count === 0) {
                break;
            }

            foreach ($rows as $row) {
                $all[] = $row;
            }

            if ($count < $pageSize) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrUpdateBusiness(array $payload, ?string $correlationId = null): array
    {
        $response = $this->kms->post('kms/business/createUpdate', $payload, $correlationId);

        if (is_array($response)) {
            return $response;
        }

        return ['raw' => $response];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function buildReferenceIndex(array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $referenceId = trim((string) Arr::get($row, 'referenceId', ''));
            if ($referenceId !== '') {
                $index[$referenceId] = $row;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function buildEmailIndex(array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $email = strtolower(trim((string) Arr::get($row, 'email', '')));
            if ($email !== '') {
                $index[$email] = $row;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function findBusiness(array $rows, string $referenceId = '', string $email = '', string $name = ''): ?array
    {
        $referenceId = trim($referenceId);
        $email = strtolower(trim($email));
        $name = trim($name);

        foreach ($rows as $row) {
            if ($referenceId !== '' && trim((string) Arr::get($row, 'referenceId', '')) === $referenceId) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if ($email !== '' && strtolower(trim((string) Arr::get($row, 'email', ''))) === $email) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if ($name !== '' && trim((string) Arr::get($row, 'name', '')) === $name) {
                return $row;
            }
        }

        return null;
    }

    public function verifyBusiness(string $referenceId = '', string $email = '', string $name = '', bool $debug = false): ?array
    {
        $since = Carbon::now()->subDays(14)->format('Y-m-d H:i:s');
        $recentRows = $this->fetchAllBusinesses($since, 200, 50, $debug);
        $recentMatch = $this->findBusiness($recentRows, $referenceId, $email, $name);
        if ($recentMatch !== null) {
            return $recentMatch;
        }

        $allRows = $this->fetchAllBusinesses(null, 200, 100, $debug);
        return $this->findBusiness($allRows, $referenceId, $email, $name);
    }

    /**
     * @param  mixed  $response
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRows(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        foreach (['rows', 'data', 'businesses', 'items'] as $key) {
            $rows = Arr::get($response, $key);
            if (is_array($rows)) {
                if (array_is_list($rows)) {
                    return array_values(array_filter($rows, 'is_array'));
                }
                if (isset($rows['rows']) && is_array($rows['rows'])) {
                    return array_values(array_filter($rows['rows'], 'is_array'));
                }
            }
        }

        if (isset($response[0]) && is_array($response[0])) {
            return array_values(array_filter($response, 'is_array'));
        }

        return [];
    }
}
