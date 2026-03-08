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

        return $candidates[0];
    }

    /**
     * @return array<int, string>
     */
    public static function globAll(string $relativePattern): array
    {
        $relativePattern = ltrim(str_replace('\\', '/', $relativePattern), '/');

        $patterns = [
            storage_path('app/' . $relativePattern),
            storage_path('app/private/' . $relativePattern),
            storage_path($relativePattern),
        ];

        $all = [];
        foreach ($patterns as $pattern) {
            $matches = glob($pattern) ?: [];
            foreach ($matches as $match) {
                if (is_file($match) || is_dir($match)) {
                    $all[] = $match;
                }
            }
        }

        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    public static function ensurePrivateDir(string $relativeDir): string
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        $dir = storage_path('app/private/' . $relativeDir);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
