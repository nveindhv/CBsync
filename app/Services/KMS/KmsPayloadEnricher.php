<?php

namespace App\Services\Kms;

/**
 * Enrich KMS createUpdate product payloads so "matrix" products actually update.
 *
 * Proven behavior (reverse engineering):
 * - Many products update fine with minimal payload.
 * - Some products (matrix/family-driven) IGNORE updates unless type_number/type_name are present,
 *   typically derived from the first N digits of the article number (often N=11).
 *
 * We include BOTH snake_case and camelCase keys for maximum compatibility:
 * - type_number / type_name (docs)
 * - typeNumber / typeName (seen in some payloads / implementations)
 */
final class KmsPayloadEnricher
{
    /**
     * Enrich a single product array (the element inside payload['products']).
     *
     * @param array $product
     * @param int $familyLen
     * @param string $typeNameTemplate Example: 'FAMILY {type_number}'
     * @return array
     */
    public static function enrichProduct(array $product, int $familyLen = 11, string $typeNameTemplate = 'FAMILY {type_number}'): array
    {
        $familyLen = $familyLen > 0 ? $familyLen : 11;

        // Do not overwrite explicit type_number/typeName etc if caller already provided.
        $hasAnyType =
            array_key_exists('type_number', $product) ||
            array_key_exists('type_name', $product) ||
            array_key_exists('typeNumber', $product) ||
            array_key_exists('typeName', $product);

        if ($hasAnyType) {
            return $product;
        }

        $article = (string)($product['article_number'] ?? ($product['articleNumber'] ?? ''));

        if ($article === '') {
            return $product;
        }

        $typeNumber = substr($article, 0, $familyLen);
        if ($typeNumber === '') {
            return $product;
        }

        $typeName = str_replace('{type_number}', $typeNumber, $typeNameTemplate);

        // Add both styles.
        $product['type_number'] = $typeNumber;
        $product['typeNumber'] = $typeNumber;
        $product['type_name'] = $typeName;
        $product['typeName'] = $typeName;

        return $product;
    }

    /**
     * Enrich a full createUpdate payload (expects ['products' => [ ... ]]).
     *
     * @param array $payload
     * @param int $familyLen
     * @param string $typeNameTemplate
     * @return array
     */
    public static function enrichCreateUpdatePayload(array $payload, int $familyLen = 11, string $typeNameTemplate = 'FAMILY {type_number}'): array
    {
        if (!isset($payload['products']) || !is_array($payload['products'])) {
            return $payload;
        }

        $out = $payload;
        $out['products'] = array_map(
            fn ($p) => is_array($p) ? self::enrichProduct($p, $familyLen, $typeNameTemplate) : $p,
            $payload['products']
        );

        return $out;
    }
}
