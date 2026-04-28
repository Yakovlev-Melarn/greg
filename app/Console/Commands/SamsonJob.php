<?php

namespace App\Console\Commands;

use App\Jobs\OcCreateSamsonCard;
use App\Jobs\SamsonCategories;
use App\Jobs\SamsonProducts;
use Illuminate\Console\Command;

class SamsonJob extends Command
{
    protected $signature = 'SamsonJob:make {method}';
    protected $description = '{method}';
    public function handle(){
        $method = $this->argument('method');
        $this->$method();
    }
    protected function categories(): void
    {
        SamsonCategories::dispatch();
    }
    protected function products(): void
    {
        SamsonProducts::dispatch();
    }

    protected function addProducts(): void
    {
        OcCreateSamsonCard::dispatch();
    }
}
