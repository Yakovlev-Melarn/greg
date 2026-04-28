<?php

namespace App\Jobs;

use App\Libs\SamsonClient;
use App\Models\SamsonCategory;
use App\Services\CategoryPathService;
use App\Services\ProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SamsonProducts implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 1;
    public int $timeout = 3600;
    protected string $url;
    public function __construct(string $url='')
    {
        $this->url = $url;
    }
    public function handle(): void
    {
        $result = SamsonClient::getProducts($this->url);
        $service = new ProductImportService();
        $service->importFromApi($result);
        if(isset($result['meta']['pagination']['next'])){
            SamsonProducts::dispatch($result['meta']['pagination']['next']);
        }
    }
}
