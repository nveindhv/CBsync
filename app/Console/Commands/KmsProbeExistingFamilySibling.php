        }

        $first = $node[0] ?? null;
        return is_array($first);
    }

    private function compactRow($row): ?array
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

    private function reportPath(string $filename): string
    {
        $dir = storage_path('app/private/kms_scan/live_family_probes');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $filename;
    }
}
