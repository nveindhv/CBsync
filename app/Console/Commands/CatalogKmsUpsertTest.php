<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogKmsUpsertTest extends Command
{
    protected $signature = 'catalog:kms:upsert-test
        {articleNumber}
        {--ean=}
        {--price=12.34}
        {--purchase-price=0}
        {--supplier-name=}
        {--category-name=}
        {--debug}';

    protected $description = 'Diagnostic createUpdate test for KMS';

    public function handle()
    {
        $kms = app(\App\Services\Kms\KmsClient::class);

        $article = (string)$this->argument('articleNumber');
        $ean = $this->option('ean') ?: $this->eanFromArticle($article);
        $price = (float)$this->option('price');
        $purchase = (float)$this->option('purchase-price');
        $supplier = (string)$this->option('supplier-name');
        $category = (string)$this->option('category-name');
        $debug = (bool)$this->option('debug');

        $name = "TEST " . $article;

        $payload = [
            "products" => [[
                "article_number" => $article,
                "ean" => $ean,
                "name" => $name,
                "price" => $price,
                "purchase_price" => $purchase,
                "supplier_name" => $supplier,
                "category_name" => $category
            ]]
        ];

        $this->line("PAYLOAD=" . json_encode($payload));

        $resp = $kms->post("kms/product/createUpdate",$payload,(string)Str::uuid());
        $this->line("CREATEUPDATE=" . json_encode($resp));

        $after = $kms->post("kms/product/getProducts",[
            "offset"=>0,
            "limit"=>5,
            "articleNumber"=>$article
        ],(string)Str::uuid());

        $this->line("AFTER=" . json_encode($after));

        return 0;
    }

    private function eanFromArticle(string $article): string
    {
        $digits = preg_replace('/\D+/','',$article);
        $base = str_pad(substr($digits,-12),12,'0',STR_PAD_LEFT);

        $sum=0;
        for($i=0;$i<12;$i++){
            $n=(int)$base[$i];
            $sum+=($i%2===0)?$n:$n*3;
        }
        $check=(10-($sum%10))%10;

        return $base.$check;
    }
}