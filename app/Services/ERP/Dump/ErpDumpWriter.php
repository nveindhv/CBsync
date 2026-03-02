<?php

namespace App\Services\ERP\Dump;

use Illuminate\Support\Facades\Storage;

class ErpDumpWriter
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $baseDir = 'erp_dump'
    ) {}

    public function writePage(string $resource, int $offset, int $limit, array $payload, array $meta = []): string
    {
        $resource = trim($resource);

        $path = $this->baseDir . '/' . $resource . '/offset_' . $offset . '_limit_' . $limit . '.json';

        $doc = [
            'meta' => array_merge([
                'resource' => $resource,
                'offset' => $offset,
                'limit' => $limit,
                'dumped_at' => now()->toIso8601String(),
            ], $meta),
            'payload' => $payload,
        ];

        Storage::disk($this->disk)->put($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
