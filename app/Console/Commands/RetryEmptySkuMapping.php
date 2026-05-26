<?php

namespace App\Console\Commands;

use App\Jobs\SimJob;
use App\Models\SkuMapping;
use App\Services\SimCalcPriceEligibility;
use Illuminate\Console\Command;

class RetryEmptySkuMapping extends Command
{
    protected $signature = 'sku-mapping:retry-empty {--limit=200} {--older-than=10}';

    protected $description = 'Requeue calcPrice for sku mappings with purchase_price = null';

    public function handle(): int
    {
        $limit = max((int) $this->option('limit'), 1);
        $olderThanMinutes = max((int) $this->option('older-than'), 0);
        $cutoff = now()->subMinutes($olderThanMinutes);

        $rows = SkuMapping::query()
            ->whereNull('purchase_price')
            ->where(function ($q) {
                $q->where('blocked', false)->orWhereNull('blocked');
            })
            ->where(function ($q) {
                $q->where('user_blocked', false)->orWhereNull('user_blocked');
            })
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'origSku']);

        if ($rows->isEmpty()) {
            $this->info('No empty skuMapping rows found for retry.');

            return self::SUCCESS;
        }

        $eligibility = new SimCalcPriceEligibility;
        $queued = 0;
        $skippedWb = 0;
        foreach ($rows as $row) {
            if (empty($row->origSku)) {
                continue;
            }

            if (! $eligibility->shouldRunCalcPrice((string) $row->origSku)) {
                $skippedWb++;

                continue;
            }

            SimJob::dispatch('calcPrice', ['sid' => $row->origSku])->onQueue('updateCardsProcess');
            $queued++;
        }

        $this->info("Queued {$queued} calcPrice jobs for empty skuMapping rows (skipped WB supplier: {$skippedWb}).");

        return self::SUCCESS;
    }
}
