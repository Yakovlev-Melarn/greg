<?php

namespace App\Services;

use App\Models\Cards;
use App\Models\ProductQueue;
use App\Models\Sellers;
use App\Models\SellerWarehouseStockHistory;
use App\Models\SellerWarehouseStockSnapshot;
use App\Models\SkuMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CardModerationService
{
    public function quarantineBySupplierVendorCodes(array $supplierVendorCodes): array
    {
        $results = [];
        $summary = [
            'total' => count($supplierVendorCodes),
            'processed' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];

        foreach ($supplierVendorCodes as $supplierVendorCode) {
            $results[] = $this->processCode((string)$supplierVendorCode);
        }

        foreach ($results as $row) {
            if ($row['status'] === 'success') {
                $summary['processed']++;
            } elseif ($row['status'] === 'not_found') {
                $summary['not_found']++;
            } else {
                $summary['errors']++;
            }
        }

        return [
            'summary' => $summary,
            'items' => $results,
        ];
    }

    /**
     * Полное удаление по supplierVendorCode: один проход в WB батчами по всем карточкам
     * из запроса (по nmID), затем удаление из БД.
     *
     * @return array{summary: array{total: int, processed: int, not_found: int, errors: int}, items: list<array<string, mixed>>}
     */
    public function hardDeleteBySupplierVendorCodes(array $supplierVendorCodes): array
    {
        $normalized = [];
        foreach ($supplierVendorCodes as $i => $raw) {
            $normalized[$i] = trim((string) $raw);
        }

        $uniqueCodesOrdered = [];
        $seenCode = [];
        foreach ($normalized as $c) {
            if ($c === '' || isset($seenCode[$c])) {
                continue;
            }
            $seenCode[$c] = true;
            $uniqueCodesOrdered[] = $c;
        }

        $snapshot = $uniqueCodesOrdered === []
            ? collect()
            : Cards::query()->whereIn('supplierVendorCode', $uniqueCodesOrdered)->get()->groupBy('supplierVendorCode');

        $validationErrors = [];
        $wbCards = collect();
        foreach ($uniqueCodesOrdered as $code) {
            $group = $snapshot->get($code, collect());
            if ($group->isEmpty()) {
                continue;
            }
            foreach ($group as $card) {
                if ((int) ($card->nmID ?: 0) <= 0) {
                    $validationErrors[$code] = 'Не задан nmID для корзины WB (карточка id='.$card->id.')';

                    break;
                }
            }
            if (isset($validationErrors[$code])) {
                continue;
            }
            foreach ($group as $card) {
                $wbCards->push($card);
            }
        }
        $wbCards = $wbCards->unique('id')->values();

        $wbError = null;
        if ($wbCards->isNotEmpty()) {
            $wbError = $this->moveWbTrashForCards($wbCards);
        }

        $purgeStats = [];
        if ($wbError === null && $wbCards->isNotEmpty()) {
            try {
                DB::transaction(function () use ($uniqueCodesOrdered, $snapshot, $validationErrors, &$purgeStats): void {
                    foreach ($uniqueCodesOrdered as $code) {
                        if (isset($validationErrors[$code])) {
                            continue;
                        }
                        $group = $snapshot->get($code, collect());
                        if ($group->isEmpty()) {
                            continue;
                        }
                        $purgeStats[$code] = $this->purgeLocalDataForCards($group);
                    }
                });
            } catch (\Throwable $e) {
                $wbError = 'Ошибка удаления из БД после успешного ответа WB: '.$e->getMessage();
                $purgeStats = [];
            }
        }

        $results = [];
        $emittedSuccessForCode = [];
        foreach ($supplierVendorCodes as $i => $_) {
            $code = $normalized[$i] ?? '';
            if ($code === '') {
                $results[] = [
                    'supplierVendorCode' => $supplierVendorCodes[$i] ?? '',
                    'status' => 'error',
                    'message' => 'Пустой supplierVendorCode',
                ];

                continue;
            }

            $group = $snapshot->get($code, collect());
            if ($group->isEmpty()) {
                $results[] = [
                    'supplierVendorCode' => $code,
                    'status' => 'not_found',
                    'message' => 'Карточка не найдена в таблице cards',
                ];

                continue;
            }

            if (isset($validationErrors[$code])) {
                $results[] = [
                    'supplierVendorCode' => $code,
                    'status' => 'error',
                    'message' => $validationErrors[$code],
                ];

                continue;
            }

            if ($wbError !== null) {
                $results[] = [
                    'supplierVendorCode' => $code,
                    'status' => 'error',
                    'message' => $wbError,
                ];

                continue;
            }

            $stats = $purgeStats[$code] ?? null;
            if ($stats === null) {
                $results[] = [
                    'supplierVendorCode' => $code,
                    'status' => 'error',
                    'message' => 'Нет данных очистки (внутренняя ошибка)',
                ];

                continue;
            }

            if (isset($emittedSuccessForCode[$code])) {
                $results[] = [
                    'supplierVendorCode' => $code,
                    'status' => 'success',
                    'message' => 'Повтор строки — тот же артикул уже удалён выше в этом запросе',
                    'deleted_cards' => 0,
                    'deleted_sku_mappings' => 0,
                    'deleted_product_queues' => 0,
                    'deleted_stock_snapshots' => 0,
                    'deleted_stock_histories' => 0,
                    'wb_nm_ids' => [],
                ];

                continue;
            }

            $emittedSuccessForCode[$code] = true;
            $results[] = [
                'supplierVendorCode' => $code,
                'status' => 'success',
                'message' => 'Карточки удалены из БД, маппинг и очередь очищены; nmID отправлены в корзину WB',
                'deleted_cards' => $stats['deleted_cards'],
                'deleted_sku_mappings' => $stats['deleted_sku_mappings'],
                'deleted_product_queues' => $stats['deleted_product_queues'],
                'deleted_stock_snapshots' => $stats['deleted_stock_snapshots'],
                'deleted_stock_histories' => $stats['deleted_stock_histories'],
                'wb_nm_ids' => $stats['wb_nm_ids'],
            ];
        }

        $summary = [
            'total' => count($supplierVendorCodes),
            'processed' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];
        foreach ($results as $row) {
            if ($row['status'] === 'success') {
                $summary['processed']++;
            } elseif ($row['status'] === 'not_found') {
                $summary['not_found']++;
            } else {
                $summary['errors']++;
            }
        }

        return [
            'summary' => $summary,
            'items' => $results,
        ];
    }

    private function processCode(string $supplierVendorCode): array
    {
        $code = trim($supplierVendorCode);
        if ($code === '') {
            return [
                'supplierVendorCode' => $supplierVendorCode,
                'status' => 'error',
                'message' => 'Пустой supplierVendorCode',
            ];
        }

        try {
            return DB::transaction(function () use ($code) {
                $card = Cards::where('supplierVendorCode', $code)->first();
                if (!$card) {
                    return [
                        'supplierVendorCode' => $code,
                        'status' => 'not_found',
                        'message' => 'Карточка не найдена в таблице cards',
                    ];
                }

                $updatedMappings = SkuMapping::where('origSku', $card->vendorCode)->update(['blocked' => 1]);

                $queueSku = $this->resolveQueueSku($card);
                if ($queueSku === null) {
                    return [
                        'supplierVendorCode' => $code,
                        'status' => 'error',
                        'message' => 'Не удалось определить идентификатор для очереди (пустые sku и nmID)',
                    ];
                }

                $queue = ProductQueue::updateOrCreate(
                    ['sku' => $queueSku],
                    [
                        'prefix' => null,
                        'price' => null,
                        'blocked' => 1,
                    ]
                );

                return [
                    'supplierVendorCode' => $code,
                    'status' => 'success',
                    'message' => 'Карточка помещена в карантин',
                    'card' => [
                        'id' => $card->id,
                        'sku' => $card->sku,
                        'queueSku' => $queueSku,
                        'vendorCode' => $card->vendorCode,
                    ],
                    'skuMappingUpdated' => $updatedMappings,
                    'queueId' => $queue->id,
                ];
            });
        } catch (\Throwable $e) {
            return [
                'supplierVendorCode' => $code,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ключ строки в product_queues: приоритетно wb sku карточки, иначе nmID (как в CloneProductsJob для очереди).
     */
    private function resolveQueueSku(Cards $card): ?string
    {
        $fromSku = trim((string) ($card->sku ?? ''));
        if ($fromSku !== '') {
            return $fromSku;
        }

        if ($card->nmID !== null && (string) $card->nmID !== '') {
            return (string) $card->nmID;
        }

        $fromSvc = trim((string) ($card->supplierVendorCode ?? ''));
        if ($fromSvc !== '') {
            return $fromSvc;
        }

        return null;
    }

    /**
     * @param  Collection<int, Cards>  $cards
     */
    private function moveWbTrashForCards(Collection $cards): ?string
    {
        $bySeller = [];
        foreach ($cards as $card) {
            $nm = (int) ($card->nmID ?: 0);
            if ($nm <= 0) {
                return 'Не задан nmID для корзины WB (карточка id='.$card->id.')';
            }
            $sid = (int) $card->sellerID;
            $bySeller[$sid][] = $nm;
        }

        foreach ($bySeller as $sellerId => $nmIds) {
            $nmIds = array_values(array_unique(array_filter($nmIds)));
            $seller = Sellers::find($sellerId);
            if (! $seller) {
                return 'Продавец id='.$sellerId.' не найден';
            }
            $key = trim((string) ($seller->wb_api_key ?? ''));
            if ($key === '') {
                return 'У продавца id='.$sellerId.' не задан wb_api_key';
            }

            $service = new WildberriesService($key, []);
            try {
                $wbErr = $service->moveCardsToTrashBatchedWithRetry($nmIds);
                if ($wbErr !== null) {
                    return 'Wildberries (продавец id='.$sellerId.'): '.$wbErr;
                }
            } catch (\Throwable $e) {
                return 'Ошибка запроса в корзину WB: '.$e->getMessage();
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Cards>  $cards
     * @return array{
     *     deleted_cards: int,
     *     deleted_sku_mappings: int,
     *     deleted_product_queues: int,
     *     deleted_stock_snapshots: int,
     *     deleted_stock_histories: int,
     *     wb_nm_ids: list<int>
     * }
     */
    private function purgeLocalDataForCards(Collection $cards): array
    {
        $deletedSkuMappings = 0;
        $deletedProductQueues = 0;
        $deletedSnapshots = 0;
        $deletedHistories = 0;
        $nmIds = [];

        foreach ($cards as $card) {
            $nmIds[] = (int) $card->nmID;
            $queueSku = $this->resolveQueueSku($card);
            if ($queueSku !== null) {
                $deletedProductQueues += ProductQueue::query()->where('sku', $queueSku)->delete();
            }
            $deletedSkuMappings += $this->resolveMappingQueryForCard($card)->delete();

            $chrt = $this->parseChrtId($card->chrtID);
            if ($chrt !== null) {
                if (Schema::hasTable('seller_warehouse_stock_snapshots')) {
                    $deletedSnapshots += SellerWarehouseStockSnapshot::query()->where('chrt_id', $chrt)->delete();
                }
                if (Schema::hasTable('seller_warehouse_stock_histories')) {
                    $deletedHistories += SellerWarehouseStockHistory::query()->where('chrt_id', $chrt)->delete();
                }
            }
        }

        $ids = $cards->pluck('id')->all();
        $deletedCards = Cards::query()->whereIn('id', $ids)->delete();

        return [
            'deleted_cards' => $deletedCards,
            'deleted_sku_mappings' => $deletedSkuMappings,
            'deleted_product_queues' => $deletedProductQueues,
            'deleted_stock_snapshots' => $deletedSnapshots,
            'deleted_stock_histories' => $deletedHistories,
            'wb_nm_ids' => array_values(array_unique(array_filter($nmIds))),
        ];
    }

    private function resolveMappingQueryForCard(Cards $card): Builder
    {
        if ((int) $card->supplier === 20) {
            return SkuMapping::query()->where('origSku', (string) $card->vendorCode);
        }

        return SkuMapping::query()->where('wbSku', (string) $card->vendorCode);
    }

    private function parseChrtId(mixed $chrt): ?int
    {
        if ($chrt === null || $chrt === '') {
            return null;
        }
        if (is_numeric($chrt)) {
            $v = (int) $chrt;

            return $v > 0 ? $v : null;
        }

        return null;
    }
}
