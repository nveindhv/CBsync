<?php

namespace App\Services\Sync;

use RuntimeException;

class SyncStateStore
{
    private string $path;

    public function __construct(string $filename)
    {
        $this->path = storage_path('app/' . ltrim($filename, '/'));
    }

    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function write(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode sync state JSON.');
        }

        $ok = @file_put_contents($this->path, $json);
        if ($ok === false) {
            throw new RuntimeException("Failed to write sync state file: {$this->path}");
        }
    }

    public function setLastSuccessfulProductsSyncAt(string $iso8601): void
    {
        $state = $this->read();
        $state['products']['last_successful_sync_at'] = $iso8601;
        $this->write($state);
    }
}
