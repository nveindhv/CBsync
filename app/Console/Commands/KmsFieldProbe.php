<?php
namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsFieldProbe extends Command
{
    protected $signature = 'kms:probe:fields
        {articleNumber : Article number}
        {--ean= : EAN}
        {--debug : Show payloads}';

    protected $description = 'Test which KMS fields can be updated by trying them one-by-one and doing a full update.';

    public function handle(KmsClient $kms): int
    {
        $correlationId = (string) Str::uuid();
        $article = (string) $this->argument('articleNumber');
        $ean = (string) $this->option('ean');
        $debug = (bool) $this->option('debug');

        $this->line("=== FIELD PROBE START ===");
        $this->line("Article: {$article}");

        $before = $kms->post('kms/product/getProducts',[
            'offset'=>0,
            'limit'=>10,
            'articleNumber'=>$article
        ],$correlationId);

        if(!is_array($before) || count($before)==0){
            $this->error("Product not found in KMS.");
            return self::FAILURE;
        }

        $product = reset($before);

        $tests = [
            'price' => 11.11,
            'purchasePrice' => 5.55,
            'name' => $product['name']." TEST",
            'unit' => $product['unit'],
            'brand' => $product['brand'],
            'color' => $product['color'],
            'size' => $product['size']
        ];

        foreach($tests as $field=>$value){

            $this->line("");
            $this->line("---- Testing field: {$field} ----");

            $payload=[
                'products'=>[[
                    'articleNumber'=>$article,
                    'article_number'=>$article,
                    'ean'=>$ean ?: ($product['ean'] ?? null),
                    $field=>$value
                ]]
            ];

            if($debug){
                $this->line("PAYLOAD:");
                $this->line(json_encode($payload));
            }

            $kms->post('kms/product/createUpdate',$payload,$correlationId);

            $after=$kms->post('kms/product/getProducts',[
                'offset'=>0,
                'limit'=>10,
                'articleNumber'=>$article
            ],$correlationId);

            $a=reset($after);

            $beforeValue=$product[$field] ?? null;
            $afterValue=$a[$field] ?? null;

            $this->line("Before: ".json_encode($beforeValue));
            $this->line("After : ".json_encode($afterValue));

            if($beforeValue!==$afterValue){
                $this->info("UPDATED ✔");
            }else{
                $this->warn("NO CHANGE ✖");
            }
        }

        $this->line("");
        $this->line("=== FULL PRODUCT UPDATE TEST ===");

        $fullPayload=[
            'products'=>[[
                'articleNumber'=>$article,
                'article_number'=>$article,
                'ean'=>$ean ?: ($product['ean'] ?? null),
                'name'=>$product['name']." FULLTEST",
                'price'=>22.22,
                'purchasePrice'=>7.77,
                'unit'=>$product['unit'],
                'brand'=>$product['brand'],
                'color'=>$product['color'],
                'size'=>$product['size'],
                'active'=>true,
                'deleted'=>false
            ]]
        ];

        if($debug){
            $this->line(json_encode($fullPayload));
        }

        $kms->post('kms/product/createUpdate',$fullPayload,$correlationId);

        $after=$kms->post('kms/product/getProducts',[
            'offset'=>0,
            'limit'=>10,
            'articleNumber'=>$article
        ],$correlationId);

        $this->line("FULL UPDATE RESULT:");
        $this->line(json_encode($after,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
