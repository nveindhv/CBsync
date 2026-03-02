<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Kms\KmsClient;

class KmsTokenTest extends Command
{
    protected $signature = 'kms:token:test';
    protected $description = 'KMS: Test of token ophalen werkt (login)';

    public function handle(KmsClient $client): int
    {
        try {
            $token = $client->getAccessToken();
            $this->info('OK. Access token opgehaald. (lengte=' . strlen($token) . ')');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Token ophalen faalde: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
