<?php

namespace App\Jobs;

use App\Models\SkuMapping;
use App\Models\SystemNotification;
use App\Services\SkuPriceRecalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RecalculateSkuPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 0;

    public function __construct(
        private readonly float $profitMarginPercent
    ) {}

    public function handle(): void
    {
        $margin = $this->profitMarginPercent / 100;
        $updated = 0;
        $skipped = 0;

        SkuMapping::query()
            ->whereNotNull('purchase_price')
            ->whereNotNull('logistics_cost')
            ->where(function ($query) {
                $query->where('blocked', false)->orWhereNull('blocked');
            })
            ->chunkById(500, function ($mappings) use (&$updated, &$skipped, $margin) {
                foreach ($mappings as $mapping) {
                    $purchase = (float) $mapping->purchase_price;
                    $logistics = (float) $mapping->logistics_cost;

                    if ($purchase <= 0) {
                        $skipped++;

                        continue;
                    }

                    $calc = SkuPriceRecalculationService::calculateFromPurchaseAndLogistics(
                        $purchase,
                        $logistics,
                        $margin
                    );

                    $mapping->fill($calc);
                    $mapping->needUpdatePrice = true;
                    $mapping->save();
                    $updated++;
                }
            });

        SystemNotification::create([
            'title' => 'Пересчет цен завершен',
            'message' => "Наценка {$this->profitMarginPercent}%. Обновлено: {$updated}. Пропущено: {$skipped}.",
            'level' => 'success',
            'source' => 'recalculate_prices_job',
            'meta' => [
                'profit_margin_percent' => $this->profitMarginPercent,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        SystemNotification::create([
            'title' => 'Ошибка пересчета цен',
            'message' => $exception->getMessage(),
            'level' => 'error',
            'source' => 'recalculate_prices_job',
        ]);
    }
}
