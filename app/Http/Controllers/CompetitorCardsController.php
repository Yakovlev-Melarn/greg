<?php

namespace App\Http\Controllers;

use App\Models\Cards;
use App\Models\Category;
use App\Models\ProductQueue;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;

class CompetitorCardsController
{
    private const DAILY_CARD_LIMIT = 1000;

    public function index(): Factory|View
    {
        $sellerId = Session::get('seller');
        $cardsCreatedToday = 0;
        $orphanCardsCount = 0;
        if ($sellerId) {
            $cardsCreatedToday = Cards::query()
                ->where('sellerID', $sellerId)
                ->whereNotNull('wb_created_at')
                ->whereDate('wb_created_at', now())
                ->count();
            $orphanCardsCount = Cards::query()
                ->where('sellerID', $sellerId)
                ->where('orphan_for_clone', true)
                ->count();
        }

        $cloneQueueReadyCount = ProductQueue::query()->where('blocked', 0)->count();

        $categoriesUncheckedCount = Category::query()->where('checked', 0)->count();

        $quantityRemaining = max(0, self::DAILY_CARD_LIMIT - $cardsCreatedToday);

        return view('CompetitorCards/index', [
            'quantityOptions' => $this->buildQuantityOptions($quantityRemaining),
            'quantitySendOptions' => $this->buildQuantityOptions($quantityRemaining),
            'quantityQueueFillOptions' => $this->buildQuantityQueueFillOptions(),
            'cardsCreatedToday' => $cardsCreatedToday,
            'quantityRemaining' => $quantityRemaining,
            'dailyCardLimit' => self::DAILY_CARD_LIMIT,
            'orphanCardsCount' => $orphanCardsCount,
            'cloneQueueReadyCount' => $cloneQueueReadyCount,
            'orphanScanQuantityOptions' => $this->buildQuantityQueueFillOptions(),
            'orphanCatalogQuantityOptions' => $this->buildOrphanCatalogScanQuantityOptions(),
            'categoriesUncheckedCount' => $categoriesUncheckedCount,
        ]);
    }

    /**
     * Лимит «сколько позиций из выдачи WB просмотреть» при обходе категорий для сирот.
     *
     * @return list<int>
     */
    private function buildOrphanCatalogScanQuantityOptions(): array
    {
        $steps = [1000, 5000, 10000, 25000, 50000, 100000];
        $out = [];
        foreach ($steps as $step) {
            $out[] = $step;
        }

        return array_values(array_unique($out));
    }

    /**
     * Опции для режима «только очередь» (до 5000), без привязки к дневному лимиту WB.
     *
     * @return list<int>
     */
    private function buildQuantityQueueFillOptions(): array
    {
        $cap = 5000;
        $steps = [1, 10, 100, 1000, 5000];
        $out = [];
        foreach ($steps as $step) {
            if ($step <= $cap) {
                $out[] = $step;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<int>
     */
    private function buildQuantityOptions(int $remaining): array
    {
        if ($remaining <= 0) {
            return [];
        }

        $steps = [1, 10, 100, 1000];
        $out = [];
        foreach ($steps as $step) {
            if ($step <= $remaining) {
                $out[] = $step;
            }
        }

        if (! in_array($remaining, $out, true)) {
            $out[] = $remaining;
        }

        sort($out, SORT_NUMERIC);

        return array_values(array_unique($out));
    }
}
