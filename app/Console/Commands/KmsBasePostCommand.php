<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Base class for KMS POST commands.
 * - Supports --file=<json> to pass an arbitrary payload.
 * - Supports --dry-run to print payload without calling KMS.
 */
abstract class KmsBasePostCommand extends Command
{
    protected KmsClient $client;

    public function __construct(KmsClient $client)
    {
        parent::__construct();
        $this->client = $client;
    }

    /** Child classes must return KMS endpoint path like 'kms/product/createUpdate' */
    abstract protected function endpoint(): string;

    /** Child classes may build payload from CLI options (ignored if --file provided) */
    protected function buildPayload(): array
    {
        return [];
    }

    protected function getPayloadFromFile(string $path): array
    {
        if (!File::exists($path)) {
            $this->error("Payload file not found: {$path}");
            return [];
        }

        $raw = File::get($path);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            $this->error("Invalid JSON in payload file: {$path}");
            return [];
        }

        return $json;
    }

    public function handle(): int
    {
        $payload = [];
        $file = $this->option('file');

        if ($file) {
            $payload = $this->getPayloadFromFile($file);
            if ($payload === []) {
                return self::FAILURE;
            }
        } else {
            $payload = $this->buildPayload();
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line("POST {$this->endpoint()}");
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($dryRun) {
            $this->info('Dry-run: request not sent.');
            return self::SUCCESS;
        }

        try {
            $response = $this->client->post($this->endpoint(), $payload);

            if (is_array($response)) {
                $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line((string) $response);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('KMS request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
