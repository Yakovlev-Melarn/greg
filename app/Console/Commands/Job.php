<?php

namespace App\Console\Commands;

use App\Jobs\GPTJob;
use App\Jobs\OcCreateWbCard;
use App\Jobs\WbJob;
use Illuminate\Console\Command;

class Job extends Command
{
    protected $signature = 'job:make {method} {param?} {oldPrice?} {newPrice?}';
    protected $description = '{method} {nmId|seller?} {oldPrice?} {newPrice?}';
    private mixed $param;
    private mixed $oldPrice;
    private mixed $newPrice;

    public function handle()
    {
        $this->param = $this->argument('param');
        $this->oldPrice = $this->argument('oldPrice');
        $this->newPrice = $this->argument('newPrice');
        $method = $this->argument('method');
        $this->$method();
    }

    protected function updatePrice(): void
    {
        WbJob::dispatch('updatePrice', [])->onQueue('updatePrice');
    }

    protected function updateWbStocks(): void
    {
        WbJob::dispatch('updateStocks', ['seller_id' => $this->param])->onQueue('wbStocks');
    }

    protected function createWbCard(): void
    {
        OcCreateWbCard::dispatch($this->param, $this->oldPrice, $this->newPrice);
    }

    protected function getChatGpt(): void
    {
        GPTJob::dispatch('category', 'Обувь\Для новорожденных', ['categoryId' => 1]);
    }
}
