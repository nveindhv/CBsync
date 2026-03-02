<?php

namespace App\Services\ERP\Schema;

use RuntimeException;

class AuthorizationSchemaReader
{
    /**
     * Parse the authorization schema export (TSV / spaced columns).
     * Expected columns: Omschrijving | GET | POST | PUT | DELETE | PATCH
     */
    public function readGetEnabledResources(string $schemaPath): array
    {
        if (!is_file($schemaPath)) {
            throw new RuntimeException("Authorization schema not found: {$schemaPath}");
        }

        $lines = file($schemaPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) === 0) {
            throw new RuntimeException("Authorization schema is empty: {$schemaPath}");
        }

        $headerIdx = null;
        $getCol = null;

        // Find header line (contains GET)
        foreach ($lines as $i => $line) {
            $cols = $this->splitLine($line);
            if (count($cols) < 2) continue;

            $upper = array_map(fn ($c) => mb_strtoupper(trim($c)), $cols);
            $pos = array_search('GET', $upper, true);
            if ($pos !== false) {
                $headerIdx = $i;
                $getCol = $pos;
                break;
            }
        }

        if ($headerIdx === null || $getCol === null) {
            throw new RuntimeException('Could not find GET column in authorization schema.');
        }

        $resources = [];

        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') continue;

            $cols = $this->splitLine($line);
            if (count($cols) <= $getCol) continue;

            $resource = trim((string) ($cols[0] ?? ''));
            if ($resource === '' || mb_strtolower($resource) === 'omschrijving') continue;

            $getVal = trim((string) $cols[$getCol]);
            if ($this->isYes($getVal)) {
                $resources[] = $resource;
            }
        }

        $resources = array_values(array_unique($resources));
        sort($resources);

        return $resources;
    }

    private function splitLine(string $line): array
    {
        // Prefer tabs; fallback to 2+ spaces.
        if (str_contains($line, "\t")) {
            $cols = preg_split("/\t+/", $line) ?: [];
        } else {
            $cols = preg_split('/\s{2,}/', $line) ?: [];
        }

        return array_values(array_filter(array_map('trim', $cols), fn ($c) => $c !== ''));
    }

    private function isYes(string $value): bool
    {
        $v = mb_strtolower(trim($value));
        return in_array($v, ['ja', 'yes', 'y', 'true', '1'], true);
    }
}
