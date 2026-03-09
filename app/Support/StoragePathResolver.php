<?php

namespace App\Support;

class StoragePathResolver
{
    public static function resolve(string $relative): string
    {
        if ($relative !== '' && preg_match('~^[A-Za-z]:[\\/]~', $relative) && file_exists($relative)) {
            return $relative;
        }

        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        $candidates = [
            storage_path('app/' . $relative),
            storage_path('app/private/' . $relative),
            storage_path($relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Path not found in storage resolver: ' . $relative);
    }

    public static function globAll(string $pattern): array
    {
        $pattern = ltrim(str_replace('\\', '/', $pattern), '/');

        $all = array_merge(
            glob(storage_path('app/' . $pattern)) ?: [],
            glob(storage_path('app/private/' . $pattern)) ?: [],
            glob(storage_path($pattern)) ?: []
        );

        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    public static function ensurePrivateDir(string $relativeDir): string
    {
        $path = storage_path('app/private/' . trim(str_replace('\\', '/', $relativeDir), '/'));
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }
}
