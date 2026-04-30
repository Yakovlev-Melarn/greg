<?php

namespace App\Services\Wb;

use App\Jobs\WbJob;

final class CardSyncScheduler
{
    private const ACTION_GET_CARD_LIST = 'getCardList';
    private const QUEUE_UPDATE_CARDS_PROCESS = 'updateCardsProcess';

    public function dispatchFollowUpCardFetch(
        int $sellerId,
        int|string|null $sourceSku,
        int|string|null $queueWbSku,
        string $supplierVendorCode
    ): void {
        WbJob::dispatch(self::ACTION_GET_CARD_LIST, [
            'seller_id' => $sellerId,
            'sourceSku' => $sourceSku,
            'queueWbSku' => $queueWbSku,
            'settings' => [
                'settings' => [
                    'sort' => ['ascending' => true],
                    'cursor' => [
                        'limit' => 1,
                    ],
                    'filter' => [
                        'textSearch' => $supplierVendorCode,
                        'withPhoto' => -1,
                    ],
                ],
            ],
        ])->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS)->delay(now()->addMinute());
    }
}
