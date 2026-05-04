<?php

namespace App\Console\Commands;

use App\Models\SellerWarehouseStockHistory;
use Illuminate\Console\Command;

class PruneWarehouseStockHistory extends Command
{
    protected $signature = 'warehouse-stock:prune-history {--days=90 : Удалить записи старше указанного числа дней}';

    protected $description = 'Удалить старые строки истории остатков по складам (seller_warehouse_stock_histories)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = SellerWarehouseStockHistory::query()
            ->where('collected_at', '<', $cutoff)
            ->delete();

        $this->info("Удалено записей истории остатков: {$deleted} (старше {$days} дн.)");

        return self::SUCCESS;
    }
}
