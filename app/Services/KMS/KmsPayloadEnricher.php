<?php

namespace App\Services\Kms;

use Illuminate\Support\Arr;

class KmsPayloadEnricher
{
    public function enrichVariantUpdate(array $snapshot, array $overrides = [], bool $includeType = false, ?string $typeNumber = null): array
    {
        $article = (string) Arr::get($snapshot, 'articleNumber', Arr::get($snapshot, 'article_number', ''));
        $payload = [
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => Arr::get($snapshot, 'ean'),
        ];

        foreach (['unit', 'brand', 'color', 'size'] as $key) {
            $value = Arr::get($snapshot, $key);
            if ($value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        if ($includeType && $typeNumber) {
            $payload['type_number'] = $typeNumber;
            $payload['typeNumber'] = $typeNumber;
            $payload['type_name'] = 'FAMILY ' . $typeNumber;
            $payload['typeName'] = 'FAMILY ' . $typeNumber;
        }

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return ['products' => [$payload]];
    }
}
