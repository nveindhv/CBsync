<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;

class KmsGetReturnGoods extends KmsBaseGetCommand
{
    protected $signature = 'kms:get:returngoods
        {--limit=50}
        {--offset=0}
        {--max-pages=1}
        {--type= : Filter type (business|location)}
        {--id= : Id behorende bij type}
        {--datetime= : Datum/tijd filter (YYYY-MM-DD of YYYY-MM-DD HH:MM:SS)}
        {--exported= : Exported filter (0|1)}
        {--all : Alias om niet te falen zonder filters (stuurt exported=0)}
    ';

    protected $description = 'KMS: Haal retourgoederen op (kms/returngoods/index)';

    public function handle(KmsClient $client): int
    {
        $payload = [];

        // Retourgoederen endpoint verwacht minimaal 1 filter parameter.
        // Ondersteunde keys: type, id, dateTime, exported (+ offset/limit).
        if ($this->option('type')) {
            $payload['type'] = (string) $this->option('type');
        }
        if (($id = $this->option('id')) !== null && $id !== '') {
            $payload['id'] = (int) $id;
        }
        if ($this->option('datetime')) {
            $payload['dateTime'] = (string) $this->option('datetime');
        }
        if (($exported = $this->option('exported')) !== null && $exported !== '') {
            $payload['exported'] = (int) $exported;
        }

        // Als gebruiker niks opgeeft maar wel "--all" wil, stuur exported=0 zodat de API niet faalt.
        if ($payload === [] && $this->option('all')) {
            $payload['exported'] = 0;
        }

        return $this->callPaged($client, 'kms/returngoods/index', $payload, 'returngoods');
    }
}
