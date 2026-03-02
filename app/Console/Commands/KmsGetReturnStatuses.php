<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsGetReturnStatuses extends Command
{
    protected $signature = 'kms:get:return-statuses';

    protected $description = 'KMS: Haal retourstatussen op (kms/returngoods/getReturnStatuses)';

    public function handle(KmsClient $client): int
    {
        $endpoint = 'kms/returngoods/getReturnStatuses';
        $this->info("POST {$endpoint}");

        try {
            $data = $client->post($endpoint, []);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (config('kms.dump_enabled')) {
            $dir = trim(config('kms.dump_dir'), '/');
            $path = "{$dir}/return-statuses/offset_0_limit_0.json";
            Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Dumped to storage/app/{$path}");
        }

        return Command::SUCCESS;
    }
}
