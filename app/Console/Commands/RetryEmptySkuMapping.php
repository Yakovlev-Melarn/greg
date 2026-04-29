<?php

namespace App\Console\Commands;

use App\Jobs\SimJob;
use App\Models\SkuMapping;
use Illuminate\Console\Command;

class RetryEmptySkuMapping extends Command
{
    protected $signature = 'sku-mapping:retry-empty {--limit=200} {--older-than=10}';
    protected $description = 'Requeue calcPrice for sku mappings with purchase_price = null';

    public function handle(): int
    {
        $limit = max((int)$this->option('limit'), 1);
        $olderThanMinutes = max((int)$this->option('older-than'), 0);
        $cutoff = now()->subMinutes($olderThanMinutes);

        $rows = SkuMapping::query()
            ->whereNull('purchase_price')
            ->where('blocked', 0)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'origSku']);

        if ($rows->isEmpty()) {
            $this->info('No empty skuMapping rows found for retry.');
            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($rows as $row) {
            if (empty($row->origSku)) {
                continue;
            }

            SimJob::dispatch('calcPrice', ['sid' => $row->origSku])->onQueue('updateCardsProcess');
            $queued++;
        }

        $this->info("Queued {$queued} calcPrice jobs for empty skuMapping rows.");
        return self::SUCCESS;
    }
}
