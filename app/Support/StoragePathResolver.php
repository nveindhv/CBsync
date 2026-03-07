<?php

namespace App\Support;

class StoragePathResolver
{
    public static function resolve(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        $candidates = [
            storage_path('app/private/' . $relative),
            storage_path('app/' . $relative),
            storage_path($relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Path not found in storage resolver: ' . $relative);
    }

    public static function ensurePrivateDir(string $relativeDir): string
    {
        $path = storage_path('app/private/' . trim($relativeDir, '/'));
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }
}
