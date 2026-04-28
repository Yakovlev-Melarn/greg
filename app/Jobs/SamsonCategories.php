<?php

namespace App\Jobs;

use App\Libs\SamsonClient;
use App\Models\SamsonCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SamsonCategories implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 10;
    public int $timeout = 3600;

    public function handle(): void
    {
        $result = SamsonClient::getCategories();
        foreach ($result['data'] as $item) {
            SamsonCategory::firstOrCreate(['id' => $item['id']], $item);
            echo "Категория {$item['name']} обработана\n";
        }
    }
}
