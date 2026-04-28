<?php

namespace App\Console\Commands;

use App\Jobs\OcCreateSamsonCard;
use App\Libs\WBContent;
use App\Models\OcProduct;
use App\Models\OcProductDiscount;
use App\Models\SamsonProduct;
use Illuminate\Console\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

class StockJob extends Command
{
    protected $signature = 'Stocks:run';
    public function handle(){
        $io = new SymfonyStyle($this->input, $this->output);
        $wbCards = OcProduct::where('supplier',1)->get();
        $samsonCards = OcProduct::where('supplier',2)->orderByDesc('product_id')->get();
        $io->success('Starting...');
        $progressBar = $io->createProgressBar($wbCards->count()+$samsonCards->count());
        $progressBar->start();
        foreach ($samsonCards as $card){
            if($product = SamsonProduct::where("sku",$card->model)->first()){
                if(!$amount = $product->stocks->where('type','idp')->sum('value')){
                    $card->status = 0;
                    $io->info("Card {$card->model} is out of stock");
                } else{
                    $card->status = 1;
                }
                $card->quantity = $amount;
            } else {
                $card->quantity = 0;
                $card->status = 0;
            }
            if ($product->prices->where('type','contract')->sum('value')) {
                    $ocCreateSamsonProduct = new OcCreateSamsonCard();
                    $prices = $ocCreateSamsonProduct->calculatePrice($product);
                    $card->price = $prices['price'];
                    if ($prices['discountPrice']) {
                        if (!$ocDiscount = OcProductDiscount::where('product_id', $card->product_id)->first()) {
                            $ocDiscount = new OcProductDiscount();
                        }
                        $ocDiscount->product_id = $card->product_id;
                        $ocDiscount->customer_group_id = 1;
                        $ocDiscount->quantity = $prices['discountQuantity'];
                        $ocDiscount->price = $prices['discountPrice'];
                        $ocDiscount->save();
                    }
            } else {
                $io->error("Card {$card->model} has no price");
            }
            $card->save();
            $progressBar->advance();
            $progressBar->setFormat('Processing item %current%/%max%');
        }
        foreach ($wbCards as $card){
            $result = WBContent::getAmount($card->model);
            $card->quantity = $result;
            if($result == 0){
                $card->status = 0;
                $io->info("Card {$card->model} is out of stock");
            }
            $card->save();
            $progressBar->advance();
            $progressBar->setFormat('Processing item %current%/%max%');
        }
        $progressBar->finish();
        $io->success('Done!');
    }
}
