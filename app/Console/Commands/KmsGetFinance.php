<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsGetFinance extends Command
{
    protected $signature = 'kms:get:finance
        {id : Finance id}
    ';

    protected $description = 'KMS: Haal 1 finance record op (kms/finance/getFinance)';

    public function handle(KmsClient $client): int
    {
        $id = (string) $this->argument('id');

        try {
            $data = $client->post('kms/finance/getFinance', ['id' => $id]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (config('kms.dump_enabled')) {
            $dir = trim(config('kms.dump_dir'), '/');
            $path = "{$dir}/finance/id_{$id}.json";
            Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Dumped to storage/app/{$path}");
        }

        return Command::SUCCESS;
    }
}
