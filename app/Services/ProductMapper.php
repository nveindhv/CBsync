<?php

namespace App\Services;

class ProductMapper
{
    public function mapErpProductToKmsProduct(array $erp): array
    {
        $productCode = (string) ($erp['productCode'] ?? '');
        $description = (string) ($erp['description'] ?? '');
        $eanCode     = (string) ($erp['eanCode'] ?? '');

        // prices can be numeric or string; keep it simple & pass as-is if numeric-ish
        $price       = $erp['price'] ?? null;
        $costPrice   = $erp['costPrice'] ?? null;

        $name = trim($description) !== '' ? $description : $productCode;

        return array_filter([
            'article_number' => $productCode !== '' ? $productCode : null,
            'ean' => $eanCode !== '' ? $eanCode : null,
            'name' => $name !== '' ? $name : null,
            'description' => $description !== '' ? $description : null,
            'price' => $this->toNumberOrNull($price),
            'purchase_price' => $this->toNumberOrNull($costPrice),
        ], fn ($v) => $v !== null);
    }

    private function toNumberOrNull($value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') {
                return null;
            }
            // allow "199.95" or "199,95"
            $v = str_replace(',', '.', $v);
            if (is_numeric($v)) {
                return (float) $v;
            }
        }
        return null;
    }
}
