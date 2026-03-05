<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsReverseEngineer extends Command
{
    protected $signature = 'kms:reverse:product {article} {--ean=} {--debug}';
    protected $description = 'Reverse engineer KMS product API fields by mutating them';

    public function handle(KmsClient $kms)
    {
        $article = $this->argument('article');
        $ean = $this->option('ean');
        $debug = $this->option('debug');

        $correlation = (string) Str::uuid();

        $before = $kms->post('kms/product/getProducts',[
            'offset'=>0,
            'limit'=>10,
            'articleNumber'=>$article
        ],$correlation);

        if(!$before){
            $this->error("Product not found");
            return 1;
        }

        $product = reset($before);

        $results = [];

        foreach($product as $field=>$value){

            if(is_array($value) || $field === "id"){
                continue;
            }

            $this->line("");
            $this->info("Testing ".$field);

            $mutated = $this->mutate($value);

            $payload = [
                "products"=>[
                    [
                        "articleNumber"=>$article,
                        "article_number"=>$article,
                        "ean"=>$ean ?: ($product['ean'] ?? null),
                        $field=>$mutated
                    ]
                ]
            ];

            if($debug){
                $this->line(json_encode($payload));
            }

            $kms->post("kms/product/createUpdate",$payload,$correlation);

            $after = $kms->post("kms/product/getProducts",[
                "offset"=>0,
                "limit"=>10,
                "articleNumber"=>$article
            ],$correlation);

            $afterProduct = reset($after);

            $beforeValue = $value;
            $afterValue = $afterProduct[$field] ?? null;

            if($beforeValue !== $afterValue){
                $this->info("UPDATED ✔");
                $results[$field] = "UPDATED";
            }else{
                $this->warn("IGNORED ✖");
                $results[$field] = "IGNORED";
            }
        }

        $this->line("");
        $this->info("=== FIELD CAPABILITY MAP ===");

        foreach($results as $f=>$r){
            $this->line(str_pad($f,20)." ".$r);
        }

        return 0;
    }

    private function mutate($value)
    {
        if(is_numeric($value)){
            return $value + 1;
        }

        if(is_bool($value)){
            return !$value;
        }

        if(is_string($value)){
            return $value . "_TEST";
        }

        return $value;
    }
}
