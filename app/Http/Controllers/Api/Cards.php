<?php

namespace App\Http\Controllers\Api;

use App\Jobs\WbJob;
use App\Models\Jobs;
use App\Models\Cards as ModelsCards;

class Cards
{
    public function getList($request): array
    {
        return ModelsCards::where('sellerId', $request['seller'])
            ->limit(20)
            ->offset($request['offset'])
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
    }

    public function updateList($request): array
    {
        if (Jobs::where('queue', '=', 'updateCardsProcess')->first()) {
            return [
                'status' => 'error',
                'message' => 'Процесс уже запущен',
            ];
        }
        if (ModelsCards::count() > 0) {
            $cardData = ModelsCards::orderBy('id', 'desc')->first();
            $cursor = [
                'limit' => 100,
                'nmID' => $cardData->nmID,
                'updatedAt' => $cardData->updated_at
            ];
        } else {
            $cursor = ['limit' => 100];
        }
        WbJob::dispatch('getCardList', [
            'seller_id' => $request['seller'],
            'settings' => [
                'settings' => [
                    'sort' => ['ascending' => true],
                    'cursor' => $cursor,
                    'filter' => ['withPhoto' => -1]
                ]
            ]
        ])->onQueue('updateCardsProcess');
        return [
            'status' => 'success',
            'message' => 'Процесс запущен',
        ];
    }
}
