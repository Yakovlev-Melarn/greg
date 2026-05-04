<?php

namespace App\Console\Commands;

use App\Jobs\WbJob;
use App\Models\SellerWarehouse;
use Illuminate\Console\Command;

class DispatchDueWarehouseStocks extends Command
{
    protected $signature = 'stocks:dispatch-due';

    protected $description = 'Поставить в очередь сбор остатков для складов, у которых подошло время по stock_frequency_minutes';

    public function handle(): int
    {
        $count = 0;
        SellerWarehouse::query()
            ->where('stock_collect_enabled', true)
            ->with('seller')
            ->orderBy('id')
            ->chunkById(100, function ($warehouses) use (&$count) {
                foreach ($warehouses as $wh) {
                    if (! $wh->seller) {
                        continue;
                    }
                    $freq = max(5, (int) $wh->stock_frequency_minutes);
                    $last = $wh->stock_last_run_at;
                    if ($last && $last->diffInMinutes(now()) < $freq) {
                        continue;
                    }
                    WbJob::dispatch('collectStocks', ['warehouse_id' => $wh->id])
                        ->onQueue(WbJob::QUEUE_STOCKS);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} stock collect job(s).");

        return self::SUCCESS;
    }
}
