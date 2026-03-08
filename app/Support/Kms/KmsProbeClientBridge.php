<?php

namespace App\Support\Kms;

use Throwable;

class KmsProbeClientBridge
{
    public function lookupArticle(object $kms, string $article, bool $debug = false): array
    {
        return $this->getProducts($kms, [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ], $debug, 'lookup article=' . $article);
    }

    public function lookupEan(object $kms, string $ean, bool $debug = false): array
    {
        return $this->getProducts($kms, [
            'offset' => 0,
            'limit' => 10,
            'ean' => $ean,
        ], $debug, 'lookup ean=' . $ean);
    }

    public function getProducts(object $kms, array $payload, bool $debug = false, ?string $label = null): array
    {
        $candidates = [
            ['getProducts', [$payload]],
            ['productGetProducts', [$payload]],
            ['postProductGetProducts', [$payload]],
            ['postGetProducts', [$payload]],
            ['post', ['kms/product/getProducts', $payload]],
            ['request', ['POST', 'kms/product/getProducts', $payload]],
            ['call', ['kms/product/getProducts', $payload]],
            ['send', ['POST', 'kms/product/getProducts', $payload]],
            ['sendRequest', ['POST', 'kms/product/getProducts', $payload]],
        ];

        $rows = $this->tryCandidates($kms, $candidates);
        $normalized = $this->normalizeRows($rows);

        if ($debug && $label) {
            echo $label . ' count=' . count($normalized) . PHP_EOL;
        }

        return $normalized;
    }

    public function createUpdate(object $kms, array $payload): array
    {
        $candidates = [
            ['createUpdate', [$payload]],
            ['productCreateUpdate', [$payload]],
            ['postProductCreateUpdate', [$payload]],
            ['postCreateUpdate', [$payload]],
            ['post', ['kms/product/createUpdate', $payload]],
            ['request', ['POST', 'kms/product/createUpdate', $payload]],
            ['call', ['kms/product/createUpdate', $payload]],
            ['send', ['POST', 'kms/product/createUpdate', $payload]],
            ['sendRequest', ['POST', 'kms/product/createUpdate', $payload]],
        ];

        $result = $this->tryCandidates($kms, $candidates);
        if (is_array($result)) {
            return $result;
        }

        return ['raw' => $result];
    }

    public function compactRow($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $keys = [
            'id', 'articleNumber', 'article_number', 'ean', 'name', 'price', 'purchasePrice', 'purchase_price',
            'unit', 'brand', 'color', 'size', 'supplierName', 'supplier_name', 'typeNumber', 'type_number',
            'typeName', 'type_name', 'modifyDate',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
            }
        }

        return $out;
    }

    public function normalizeRows($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        if (array_key_exists('articleNumber', $raw) || array_key_exists('article_number', $raw)) {
            return [$raw];
        }

        $paths = [
            $raw,
            $raw['products'] ?? null,
            $raw['data'] ?? null,
            $raw['rows'] ?? null,
            $raw['result'] ?? null,
            $raw['result']['products'] ?? null,
            $raw['result']['data'] ?? null,
            $raw['response'] ?? null,
            $raw['response']['products'] ?? null,
            $raw['response']['data'] ?? null,
        ];

        foreach ($paths as $node) {
            if ($this->isListOfRows($node)) {
                return array_values(array_filter($node, 'is_array'));
            }
        }

        return [];
    }

    private function tryCandidates(object $kms, array $candidates)
    {
        $errors = [];

        foreach ($candidates as [$method, $args]) {
            try {
                return $kms->{$method}(...$args);
            } catch (Throwable $e) {
                $errors[] = $method . ': ' . $e->getMessage();
            }
        }

        throw new \RuntimeException('No working KMS client method found. Tried: ' . implode(' | ', $errors));
    }

    private function isListOfRows($node): bool
    {
        if (! is_array($node) || $node === []) {
            return false;
        }

        $first = $node[0] ?? null;
        return is_array($first);
    }
}
