<?php

namespace App\Http\Controllers;

use App\Models\Cards;
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
        if ($sellerId) {
            $cardsCreatedToday = Cards::query()
                ->where('sellerID', $sellerId)
                ->whereDate('created_at', now())
                ->count();
        }

        $quantityRemaining = max(0, self::DAILY_CARD_LIMIT - $cardsCreatedToday);

        return view('CompetitorCards/index', [
            'quantityOptions' => $this->buildQuantityOptions($quantityRemaining),
            'cardsCreatedToday' => $cardsCreatedToday,
            'quantityRemaining' => $quantityRemaining,
            'dailyCardLimit' => self::DAILY_CARD_LIMIT,
        ]);
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
