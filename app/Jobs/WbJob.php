<?php

namespace App\Jobs;

use App\DTO\Wb\CardListContext;
use App\DTO\Wb\PhotoUploadPayload;
use App\DTO\Wb\PriceUpdatePayload;
use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\Cards as CardsModel;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Models\SystemNotification;
use App\Services\WildberriesService;
use App\Services\Wb\CardSyncScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACTION_UPDATE_PRICE = 'updatePrice';
    private const ACTION_GET_CARD_LIST = 'getCardList';
    private const ACTION_UPLOAD_PHOTOS = 'uploadPhotos';
    private const QUEUE_UPDATE_CARDS_PROCESS = 'updateCardsProcess';
    private const QUEUE_UPDATE_PRICE = 'updatePrice';
    private const PRICE_UPDATE_DEFAULT_BATCH_SIZE = 1000;
    private const PRICE_UPDATE_DEFAULT_MAX_BATCHES_PER_RUN = 20;
    private const PRICE_MARGIN = 0.25;
    private const STOCK_CHUNK_SIZE = 100;
    private const STOCK_UPDATE_CHUNK_SIZE = 1000;
    private const STOCK_MAX_AMOUNT = 5;

    public int $tries = 10;
    public int $timeout = 3600;

    public function __construct(
        private readonly string $action,
        private readonly array  $params = []
    ) {}

    public function handle(): void
    {
        self::{$this->action}($this->params);
    }

    public function tries(): int
    {
        return $this->action === self::ACTION_UPDATE_PRICE ? 5 : $this->tries;
    }

    public function backoff(): array|int
    {
        return $this->action === self::ACTION_UPDATE_PRICE
            ? [30, 120, 300, 600]
            : 0;
    }

    public function failed(Throwable $exception): void
    {
        if ($this->action !== self::ACTION_UPDATE_PRICE) {
            return;
        }

        $maxAttempts = $this->tries();
        $currentAttempt = $this->attempts();

        SystemNotification::create([
            'title' => 'Ошибка обновления цен',
            'message' => "Джоба завершилась с ошибкой после попытки {$currentAttempt}/{$maxAttempts}: " . $exception->getMessage(),
            'level' => 'error',
            'source' => 'wb_update_price_job',
            'meta' => [
                'failed_at' => now()->toDateTimeString(),
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
            ],
        ]);
    }

    /**
     * @throws ConnectionException
     */
    private function getCardList(array $params): void
    {
        // Нормализуем входной payload (новые + legacy-поля), чтобы не ломать старые вызовы.
        $context = $this->buildCardListContext($params);
        if (!$context) {
            echo 'error seller_id is null';
            return;
        }

        $seller = Sellers::find($context->sellerId);
        if (!$seller) {
            Log::warning('WbJob getCardList skipped: seller not found', ['params' => $params]);
            return;
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $result = $service->getCardList($context->settings);
        $cards = $result['data']['cards'] ?? [];
        $cursorData = $result['data']['cursor'] ?? [];
        $cursor = $cursorData['nmID'] ?? null;
        $updatedAt = $cursorData['updatedAt'] ?? null;
        $total = (int)($cursorData['total'] ?? 0);

        if ($this->shouldAccumulateWbNmIdsForOrphans($params, $context)) {
            $seen = $params['wb_nm_ids_seen'] ?? [];
            foreach ($this->extractNmIdsFromWbCards($cards) as $nmId) {
                $seen[(int) $nmId] = true;
            }
            $params['wb_nm_ids_seen'] = $seen;
        }

        if (! empty($params['cards_sync_notify_start'])) {
            $this->notifyCardsSyncStarted($params, $seller);
        }

        // Обновляем/сохраняем карточки только после того, как фото уже появилось в WB.
        $this->updateCard($cards, $seller, $context->sourceSku, $context->queueWbSku);

        $isCatalogBackfill = ! empty($params['catalog_backfill']);

        if ($total === 100) {
            // Пагинация WB: если получили полный лимит, запрашиваем следующую страницу.
            $nextPayload = [
                'seller_id' => $context->sellerId,
                'sourceSku' => $context->sourceSku,
                'queueWbSku' => $context->queueWbSku,
                'settings' => [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => [
                            'limit' => 100,
                            'updatedAt' => $updatedAt,
                            'nmID' => $cursor,
                        ],
                        'filter' => ['withPhoto' => -1],
                    ],
                ],
            ];
            if ($isCatalogBackfill) {
                $nextPayload['catalog_backfill'] = true;
            } elseif (! empty($params['needs_catalog_backfill_after_incremental'])) {
                $nextPayload['needs_catalog_backfill_after_incremental'] = true;
            }
            if (! empty($params['cards_full_catalog_from_empty'])) {
                $nextPayload['cards_full_catalog_from_empty'] = true;
            }
            $this->carryCardsSyncRunId($nextPayload, $params);
            $this->carryWbNmIdsAccumulator($nextPayload, $params);
            self::dispatch(self::ACTION_GET_CARD_LIST, $nextPayload)->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS);

            return;
        }

        // Последняя страница текущего режима (total < 100)
        if ($isCatalogBackfill) {
            $this->pruneLocalCardsMissingFromWbCatalog($seller, $params, $context);
            $this->notifyCardsSyncFinishedIfTracked($params, $seller, count($cards));

            return;
        }

        if (
            ! empty($params['needs_catalog_backfill_after_incremental'])
            && ! $this->isTargetedCardListFetch($params, $context)
        ) {
            $backfillPayload = [
                'seller_id' => $context->sellerId,
                'catalog_backfill' => true,
                'wb_nm_ids_seen' => [],
                'settings' => [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => ['limit' => 100],
                        'filter' => ['withPhoto' => -1],
                    ],
                ],
            ];
            $this->carryCardsSyncRunId($backfillPayload, $params);
            self::dispatch(self::ACTION_GET_CARD_LIST, $backfillPayload)->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS);

            return;
        }

        $this->pruneLocalCardsMissingFromWbCatalog($seller, $params, $context);
        $this->notifyCardsSyncFinishedIfTracked($params, $seller, count($cards));
    }

    /**
     * nmID из ответа WB (включая позиции без сохранённого фото — они всё равно есть в каталоге).
     *
     * @return list<int>
     */
    private function extractNmIdsFromWbCards(array $cards): array
    {
        $ids = [];
        foreach ($cards as $card) {
            if (! empty($card['nmID'])) {
                $ids[] = (int) $card['nmID'];
            }
        }

        return $ids;
    }

    private function shouldAccumulateWbNmIdsForOrphans(array $params, CardListContext $context): bool
    {
        if ($this->isTargetedCardListFetch($params, $context)) {
            return false;
        }

        return ! empty($params['catalog_backfill'])
            || ! empty($params['cards_full_catalog_from_empty']);
    }

    private function carryWbNmIdsAccumulator(array &$payload, array $params): void
    {
        if (! empty($params['wb_nm_ids_seen'])) {
            $payload['wb_nm_ids_seen'] = $params['wb_nm_ids_seen'];
        }
    }

    /**
     * Удаляет из cards и skuMapping записи продавца, которых нет в полном списке nmID WB после полного обхода каталога.
     */
    private function pruneLocalCardsMissingFromWbCatalog(Sellers $seller, array $params, CardListContext $context): void
    {
        if (! $this->shouldAccumulateWbNmIdsForOrphans($params, $context)) {
            return;
        }

        $wbSet = array_keys($params['wb_nm_ids_seen'] ?? []);
        $this->pruneLocalCardsRemovedFromWb($seller, $wbSet);
        $this->reconcileCardsWithSkuMappingAfterFullCatalog($seller);
    }

    /**
     * После полного обхода WB: очистка «висячих» skuMapping, подстановка sku с nmID, удаление висячих карточек (&gt; WB) через корзину WB.
     */
    private function reconcileCardsWithSkuMappingAfterFullCatalog(Sellers $seller): void
    {
        $deletedMappings = $this->deleteSkuMappingsWithoutCards();
        if ($deletedMappings > 0) {
            Log::info('WbJob removed skuMapping rows without matching cards', [
                'seller_id' => $seller->id,
                'deleted_mappings' => $deletedMappings,
            ]);
        }

        $filled = $this->backfillNullCardSkuFromSkuMapping($seller);
        if ($filled > 0) {
            Log::info('WbJob backfilled cards.sku from skuMapping.wbSku', [
                'seller_id' => $seller->id,
                'updated_cards' => $filled,
            ]);
        }

        $trashed = $this->trashUnmappedSupplierGt10CardsAndDelete($seller);
        if ($trashed > 0) {
            Log::info('WbJob moved unmapped orphan cards (supplier>10, sku null) to WB trash and removed locally', [
                'seller_id' => $seller->id,
                'removed_cards' => $trashed,
            ]);
        }
    }

    /**
     * Строки skuMapping без соответствующей карточки (Sima: origSku = vendorCode, WB: wbSku = vendorCode).
     */
    private function deleteSkuMappingsWithoutCards(): int
    {
        return SkuMapping::query()
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('cards as c')
                    ->where(function ($w) {
                        $w->where(function ($a) {
                            $a->where('c.supplier', 20)
                                ->whereColumn('c.vendorCode', 'skuMapping.origSku');
                        })->orWhere(function ($a) {
                            $a->where('c.supplier', 10)
                                ->whereColumn('c.vendorCode', 'skuMapping.wbSku');
                        });
                    });
            })
            ->delete();
    }

    /**
     * Дописывает cards.sku из skuMapping.wbSku для привязанных карточек с пустым sku.
     */
    private function backfillNullCardSkuFromSkuMapping(Sellers $seller): int
    {
        $sellerId = $seller->id;

        $n20 = DB::update(
            'UPDATE cards SET sku = (
                SELECT sm.wbSku FROM skuMapping sm WHERE sm.origSku = cards.vendorCode LIMIT 1
            )
            WHERE sellerID = ?
              AND supplier = 20
              AND sku IS NULL
              AND EXISTS (SELECT 1 FROM skuMapping sm WHERE sm.origSku = cards.vendorCode)',
            [$sellerId]
        );

        $n10 = DB::update(
            'UPDATE cards SET sku = (
                SELECT sm.wbSku FROM skuMapping sm WHERE sm.wbSku = cards.vendorCode LIMIT 1
            )
            WHERE sellerID = ?
              AND supplier = 10
              AND sku IS NULL
              AND EXISTS (SELECT 1 FROM skuMapping sm WHERE sm.wbSku = cards.vendorCode)',
            [$sellerId]
        );

        return (int) $n20 + (int) $n10;
    }

    /**
     * Карточки с supplier &gt; 10 (не только WB), без строки в skuMapping и с пустым sku — в корзину WB и удаление из БД.
     */
    private function trashUnmappedSupplierGt10CardsAndDelete(Sellers $seller): int
    {
        $service = new WildberriesService($seller->wb_api_key, []);
        $removed = 0;

        CardsModel::query()
            ->where('sellerID', $seller->id)
            ->where('supplier', '>', 10)
            ->whereNull('sku')
            ->orderBy('id')
            ->chunkById(50, function ($cards) use ($service, &$removed) {
                foreach ($cards as $card) {
                    if ($this->cardHasMatchingSkuMapping($card)) {
                        continue;
                    }
                    $nmId = (int) $card->nmID;
                    if ($nmId <= 0) {
                        Log::warning('WbJob skip trash: card has no nmID', [
                            'card_id' => $card->id,
                            'seller_id' => $card->sellerID,
                        ]);

                        continue;
                    }
                    try {
                        if ($service->moveCardsToTrash([$nmId])) {
                            $card->delete();
                            $removed++;
                        } else {
                            Log::warning('WbJob trash API failed for orphan card', [
                                'card_id' => $card->id,
                                'nmID' => $nmId,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('WbJob trash orphan card exception', [
                            'card_id' => $card->id,
                            'nmID' => $nmId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $removed;
    }

    private function cardHasMatchingSkuMapping(CardsModel $card): bool
    {
        $supplier = (int) $card->supplier;
        $vc = (string) ($card->vendorCode ?? '');
        if ($vc === '') {
            return false;
        }
        if ($supplier === 20) {
            return SkuMapping::query()->where('origSku', $vc)->exists();
        }
        if ($supplier === 10) {
            return SkuMapping::query()->where('wbSku', $vc)->exists();
        }

        return SkuMapping::query()
            ->where(function ($q) use ($vc) {
                $q->where('origSku', $vc)->orWhere('wbSku', $vc);
            })
            ->exists();
    }

    /**
     * @param  list<int>  $wbNmIds
     */
    private function pruneLocalCardsRemovedFromWb(Sellers $seller, array $wbNmIds): void
    {
        $wbLookup = [];
        foreach ($wbNmIds as $id) {
            $wbLookup[(int) $id] = true;
        }

        $locals = CardsModel::query()
            ->where('sellerID', $seller->id)
            ->get();

        $removed = 0;
        foreach ($locals as $card) {
            $nmId = (int) $card->nmID;
            if ($nmId === 0) {
                continue;
            }
            if (isset($wbLookup[$nmId])) {
                continue;
            }

            DB::transaction(function () use ($card) {
                $this->deleteSkuMappingsForCard($card);
                $card->delete();
            });
            $removed++;
        }

        if ($removed > 0) {
            Log::info('WbJob removed local cards absent from WB catalog', [
                'seller_id' => $seller->id,
                'removed' => $removed,
            ]);
        }
    }

    private function deleteSkuMappingsForCard(CardsModel $card): void
    {
        $supplier = (int) $card->supplier;
        $vendorCode = (string) ($card->vendorCode ?? '');
        if ($vendorCode === '') {
            return;
        }

        if ($supplier === 20) {
            SkuMapping::query()->where('origSku', $vendorCode)->delete();

            return;
        }

        if ($supplier === 10) {
            SkuMapping::query()->where('wbSku', $vendorCode)->delete();
        }
    }

    /**
     * Убирает ссылки на слоты без файла: WB не может подтянуть такое медиа и падает на media/save.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function filterReachableBasketImageUrls(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if ($this->isBasketImageUrlReachable($url)) {
                $out[] = $url;
            } else {
                Log::warning('WbJob skipped unreachable basket image URL before WB media upload', ['url' => $url]);
            }
        }

        return $out;
    }

    private function isBasketImageUrlReachable(string $url): bool
    {
        try {
            $head = Http::timeout(10)
                ->connectTimeout(5)
                ->head($url);

            if ($head->successful()) {
                return true;
            }

            if (in_array($head->status(), [405, 501], true)) {
                $range = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders(['Range' => 'bytes=0-4095'])
                    ->get($url);

                return $range->successful();
            }
        } catch (\Throwable $e) {
            Log::debug('WbJob basket image availability check failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function carryCardsSyncRunId(array &$payload, array $params): void
    {
        if (! empty($params['cards_sync_run_id'])) {
            $payload['cards_sync_run_id'] = $params['cards_sync_run_id'];
        }
    }

    private function notifyCardsSyncStarted(array $params, Sellers $seller): void
    {
        SystemNotification::create([
            'title' => 'Синхронизация каталога',
            'message' => 'Начат обход и обновление карточек WB для магазина «'.$seller->name.'».',
            'level' => 'info',
            'source' => 'wb_cards_sync',
            'meta' => [
                'seller_id' => $seller->id,
                'run_id' => $params['cards_sync_run_id'] ?? null,
                'phase' => 'started',
            ],
        ]);
    }

    private function notifyCardsSyncFinishedIfTracked(array $params, Sellers $seller, int $batchCardCount): void
    {
        if (empty($params['cards_sync_run_id'])) {
            return;
        }

        SystemNotification::create([
            'title' => 'Синхронизация каталога',
            'message' => 'Обход и обновление карточек WB для магазина «'.$seller->name.'» завершены.',
            'level' => 'success',
            'source' => 'wb_cards_sync',
            'meta' => [
                'seller_id' => $seller->id,
                'run_id' => $params['cards_sync_run_id'],
                'phase' => 'finished',
                'last_batch_cards' => $batchCardCount,
            ],
        ]);
    }

    /**
     * Точечные запросы списка (клон, follow-up по textSearch и т.д.) — без полного бэкфилла каталога.
     */
    private function isTargetedCardListFetch(array $params, CardListContext $context): bool
    {
        if ($context->sourceSku !== null && $context->sourceSku !== '') {
            return true;
        }
        if ($context->queueWbSku !== null && $context->queueWbSku !== '') {
            return true;
        }
        $filter = $params['settings']['settings']['filter'] ?? [];

        return ! empty($filter['textSearch']);
    }

    /**
     * @throws ConnectionException
     */
    private function updateStocks(array $params): void
    {
        if (empty($params['seller_id'])) {
            return;
        }
        $seller = Sellers::find($params['seller_id']);
        if (!$seller) {
            return;
        }
        $cards = $this->getSellerCards($seller);
        $vendorToChrtMap = $this->buildVendorToChrtMap($cards);
        $vendorToSupplierMap = $this->buildVendorToSupplierMap($cards);
        $stockData = $this->fetchStockQuantities($cards, $vendorToChrtMap, $vendorToSupplierMap);
        $this->sendStockUpdates($seller, $stockData);
    }

    private function getSellerCards(Sellers $seller): Collection
    {
        return $seller->cards()
            ->whereIn('supplier', [10, 20])
            ->get();
    }

    private function buildVendorToChrtMap(Collection $cards): array
    {
        return $cards->pluck('chrtID', 'vendorCode')->toArray();
    }

    /**
     * @return array<string, int>
     */
    private function buildVendorToSupplierMap(Collection $cards): array
    {
        $map = [];
        foreach ($cards as $card) {
            $vc = (string) ($card->vendorCode ?? '');
            if ($vc === '') {
                continue;
            }
            $map[$vc] = (int) $card->supplier;
        }

        return $map;
    }

    private function fetchStockQuantities(Collection $cards, array $vendorToChrtMap, array $vendorToSupplierMap): array
    {
        $vendorCodes = $cards->pluck('vendorCode')->toArray();
        $chunks = array_chunk($vendorCodes, self::STOCK_CHUNK_SIZE);
        $result = [];
        $total = count($chunks);
        echo "Очередь на запрос из " . $total . " пачек\n";
        foreach ($chunks as $chunk) {
            echo "Осталось " . ($total--) . " пачек\n";
            $vendorCodeString = implode(';', $chunk);
            $stocks = WBContent::getAmounts($vendorCodeString);
            foreach ($stocks as $vendorCode => $quantity) {
                if (isset($vendorToChrtMap[$vendorCode])) {
                    $chrtID = $vendorToChrtMap[$vendorCode];
                    $supplier = $vendorToSupplierMap[$vendorCode] ?? 10;
                    $result[] = [
                        'chrtId' => $chrtID,
                        'amount' => $quantity > self::STOCK_MAX_AMOUNT ? self::STOCK_MAX_AMOUNT : 0,
                        'supplier' => $supplier,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @throws ConnectionException
     */
    private function sendStockUpdates(Sellers $seller, array $stockData): void
    {
        $seller->loadMissing('warehouses');

        $defaultWarehouse = $seller->warehouses->firstWhere('supplier', null);
        $supplier20Warehouse = $seller->warehouses->firstWhere('supplier', 20);

        $forSupplier20 = [];
        $forDefault = [];

        foreach ($stockData as $row) {
            $supplier = (int) ($row['supplier'] ?? 10);
            $payload = [
                'chrtId' => $row['chrtId'],
                'amount' => $row['amount'],
            ];
            if ($supplier === 20) {
                $forSupplier20[] = $payload;
            } else {
                $forDefault[] = $payload;
            }
        }

        $service = new WildberriesService($seller->wb_api_key, []);

        $routes = [
            [
                'chunks' => $forSupplier20,
                'warehouse' => $supplier20Warehouse,
                'label' => 'supplier=20',
                'route_key' => 'supplier_20',
            ],
            [
                'chunks' => $forDefault,
                'warehouse' => $defaultWarehouse,
                'label' => 'по умолчанию (supplier≠20)',
                'route_key' => 'default',
            ],
        ];

        foreach ($routes as $route) {
            if ($route['chunks'] === []) {
                continue;
            }

            $warehouse = $route['warehouse'];
            if (!$warehouse) {
                Log::warning('WbJob: no warehouse configured for stock route', [
                    'seller_id' => $seller->id,
                    'route' => $route['route_key'],
                    'label' => $route['label'],
                ]);

                SystemNotification::create([
                    'title' => 'Остатки WB: склад не настроен',
                    'message' => sprintf(
                        'Для магазина «%s» не задан склад для маршрута «%s». Обновление остатков для этой группы пропущено.',
                        $seller->name,
                        $route['label']
                    ),
                    'level' => 'warning',
                    'source' => 'wb_stock_sync',
                    'meta' => [
                        'seller_id' => $seller->id,
                        'route' => $route['route_key'],
                    ],
                ]);

                continue;
            }

            $chunks = array_chunk($route['chunks'], self::STOCK_UPDATE_CHUNK_SIZE);
            $total = count($chunks);
            echo "Очередь на отправку ({$route['label']}) из " . $total . " пачек\n";
            foreach ($chunks as $chunk) {
                $service->updateStocks((int) $warehouse->wb_warehouse_id, $chunk);
                echo "Осталось " . ($total--) . " пачек\n";
            }
        }
    }

    /**
     * @throws ConnectionException
     */
    private function uploadPhotos(array $params): void
    {
        $payload = $this->buildPhotoUploadPayload($params);
        if (!$payload) {
            Log::warning('WbJob uploadPhotos skipped: invalid payload', ['params' => $params]);
            return;
        }

        $seller = Sellers::find($payload->sellerId);
        if (!$seller) {
            Log::warning('WbJob uploadPhotos skipped: seller not found', ['params' => $params]);
            return;
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $basket = Helper::getBasketNumber($payload->supplierId);
        $info = WBContent::getCardInfo($payload->supplierId);
        $photoCount = (int)($info['media']['photo_count'] ?? 0);
        if ($photoCount <= 0) {
            Log::warning('WbJob uploadPhotos skipped: source has no photos', [
                'sourceSupplierId' => $payload->supplierId,
                'nmID' => $payload->nmId,
            ]);
            return;
        }

        $data = [];
        for ($i = 1; $i <= $photoCount; $i++) {
            $data[] = "https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}"
                . "/part{$basket['mid']}/{$payload->supplierId}/images/big/{$i}.webp";
        }

        $data = $this->filterReachableBasketImageUrls($data);
        if ($data === []) {
            Log::warning('WbJob uploadPhotos skipped: no reachable basket image URLs', [
                'nmID' => $payload->nmId,
                'supplierId' => $payload->supplierId,
                'photo_count_declared' => $photoCount,
            ]);

            return;
        }

        $service->uploadPhotos($payload->nmId, $data);

        // Только для ручного обновления: сразу сохраняем первую ссылку на фото в cards.photo.
        if (!empty($params['manual_photo_refresh']) && !empty($data[0])) {
            $cardId = (int)($params['card_id'] ?? 0);
            $cardQuery = CardsModel::query()
                ->where('sellerID', $payload->sellerId)
                ->where('nmID', $payload->nmId);
            if ($cardId > 0) {
                $cardQuery->where('id', $cardId);
            }
            $card = $cardQuery->first();
            if ($card) {
                $card->photo = (string)$data[0];
                $card->save();
            }
        }
    }

    private function updateCard(
        array $cardsData,
        Sellers $seller,
        int|string|null $sourceSku = null,
        int|string|null $queueWbSku = null
    ): void
    {
        foreach ($cardsData as $card) {
            $photo = '';
            if (isset($card['photos']) && count($card['photos']) > 0) {
                $photo = $card['photos'][0]['c246x328'];
            }

            $supplierVendorCode = (string)($card['vendorCode'] ?? '');
            if ($supplierVendorCode === '') {
                continue;
            }

            if ($photo === '') {
                // Фото ещё не готовы: запускаем загрузку и откладываем повторный fetch карточки.
                $queued = self::queuePhotoUploadAndFollowUpFetch(
                    (int) $seller->id,
                    (int) $card['nmID'],
                    $supplierVendorCode,
                    $sourceSku,
                    $queueWbSku
                );
                if (! $queued) {
                    Log::warning('WbJob updateCard skipped: photo source id is not resolved', [
                        'seller_id' => $seller->id,
                        'supplierVendorCode' => $supplierVendorCode,
                        'sourceSku' => $sourceSku,
                        'queueWbSku' => $queueWbSku,
                        'nmID' => $card['nmID'] ?? null,
                    ]);
                }

                // Не сохраняем карточку до появления фото.
                continue;
            }

            $data = [
                'updated_at' => $card['updatedAt'] ?? now(),
                'nmID' => $card['nmID'],
                'sellerID' => $seller->id,
                'supplier' => Helper::getSupplier($supplierVendorCode),
                'supplierVendorCode' => $supplierVendorCode,
                'vendorCode' => Helper::getVendorCode($supplierVendorCode),
                'supplierName' => Helper::getSupplierName($supplierVendorCode),
                'productName' => $card['title'] ?? '',
                'chrtID' => $card['sizes'][0]['chrtID'] ?? 0,
                'photo' => $photo,
                // For clone flow: this is queueWbSku. For full sync calls it can be null.
                'sku' => $queueWbSku,
            ];

            $seller->cards()->updateOrCreate(
                ['nmID' => $card['nmID']],
                $data
            );
        }
    }

    /**
     * Поставить в очередь uploadPhotos; при синхронизации без фото — ещё и отложенный getCardList.
     * Ручное обновление фото: только uploadPhotos (поле photo обновляется внутри джобы).
     */
    public static function queuePhotoUploadAndFollowUpFetch(
        int $sellerId,
        int $nmId,
        string $supplierVendorCode,
        int|string|null $sourceSku = null,
        int|string|null $queueWbSku = null,
        bool $manualPhotoRefresh = false,
        ?int $cardId = null,
    ): bool {
        $photoSourceSupplierId = self::resolvePhotoSourceSupplierId($supplierVendorCode, $sourceSku, $queueWbSku);
        if ($photoSourceSupplierId <= 0) {
            return false;
        }

        self::dispatch(self::ACTION_UPLOAD_PHOTOS, [
            'supplierID' => $photoSourceSupplierId,
            'nmID' => $nmId,
            'seller_id' => $sellerId,
            'manual_photo_refresh' => $manualPhotoRefresh,
            'card_id' => $cardId,
        ])->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS);

        if (! $manualPhotoRefresh) {
            (new CardSyncScheduler())->dispatchFollowUpCardFetch(
                $sellerId,
                $sourceSku,
                $queueWbSku,
                $supplierVendorCode
            );
        }

        return true;
    }

    private static function resolvePhotoSourceSupplierId(
        string $supplierVendorCode,
        int|string|null $sourceSku = null,
        int|string|null $queueWbSku = null
    ): int
    {
        $supplierCode = strtoupper($supplierVendorCode[0] ?? '');

        // Для Sima-Land в clone-потоке приоритет у queueWbSku, затем wbSku из SkuMapping, затем sourceSku.
        if ($supplierCode === 'S') {
            $resolvedQueueWbSku = $queueWbSku;
            if (empty($resolvedQueueWbSku)) {
                $origSku = (int) Helper::getVendorCode($supplierVendorCode);
                if ($origSku > 0) {
                    $wbSkuFromMapping = SkuMapping::query()
                        ->where('origSku', (string) $origSku)
                        ->value('wbSku');
                    if (! empty($wbSkuFromMapping)) {
                        $resolvedQueueWbSku = $wbSkuFromMapping;
                    }
                }
            }
            if (! empty($resolvedQueueWbSku)) {
                return (int) $resolvedQueueWbSku;
            }
            if (! empty($sourceSku)) {
                return (int) $sourceSku;
            }
            return (int) Helper::getVendorCode($supplierVendorCode);
        }

        // Для WB пытаемся взять SKU из vendorCode.
        if ($supplierCode === 'W') {
            $vendorSku = (int)Helper::getVendorCode($supplierVendorCode);
            if ($vendorSku > 0) {
                return $vendorSku;
            }
        }

        // Fallback для legacy-вызовов.
        if (!empty($sourceSku)) {
            return (int)$sourceSku;
        }
        return (int)$queueWbSku;
    }

    private function updatePrice(): void
    {
        $startedAt = now();
        $currentAttempt = $this->attempts();
        $maxAttempts = $this->tries();
        $this->notifyPriceUpdateStarted($startedAt, $currentAttempt, $maxAttempts);

        $skuMappings = SkuMapping::with('card')
            ->where('needUpdatePrice', 1)
            ->get();

        $groupedBySeller = [];
        $processedBatches = 0;
        $reachedBatchLimit = false;
        $batchSize = max(
            1,
            min((int) env('WB_UPDATE_PRICE_BATCH_SIZE', self::PRICE_UPDATE_DEFAULT_BATCH_SIZE), self::PRICE_UPDATE_DEFAULT_BATCH_SIZE)
        );
        $maxBatchesPerRun = max(1, (int) env('WB_UPDATE_PRICE_MAX_BATCHES_PER_RUN', self::PRICE_UPDATE_DEFAULT_MAX_BATCHES_PER_RUN));

        foreach ($skuMappings as $skuMapping) {
            try {
                $pricePayload = $this->buildPricePayloadForMapping($skuMapping);
                $groupedBySeller[$pricePayload->sellerId][] = [
                    'mappingId' => $pricePayload->mappingId,
                    'priceData' => [
                        'nmID' => $pricePayload->nmId,
                        'price' => $pricePayload->price,
                    ],
                ];
            } catch (\Exception $e) {
                echo '🚨 Ошибка при подготовке цены: ' . $e->getMessage() . "\r\n";
            }
        }

        foreach ($groupedBySeller as $sellerId => $items) {
            $seller = Sellers::find($sellerId);
            if (!$seller) {
                echo "🚨 Продавец {$sellerId} не найден, пропуск группы\n";
                continue;
            }

            $service = new WildberriesService($seller->wb_api_key, []);
            $chunks = array_chunk($items, $batchSize);
            $totalChunks = count($chunks);
            echo "Отправка цен продавца {$sellerId}: {$totalChunks} пачек\n";

            foreach ($chunks as $index => $chunk) {
                if ($processedBatches >= $maxBatchesPerRun) {
                    $reachedBatchLimit = true;
                    echo "⚠️ Достигнут лимит пачек за запуск ({$maxBatchesPerRun})\n";
                    break 2;
                }

                $priceData = array_column($chunk, 'priceData');
                $mappingIds = array_column($chunk, 'mappingId');

                try {
                    $resultUpdatePrice = $service->updatePrice($priceData);
                    if ($resultUpdatePrice) {
                        SkuMapping::whereIn('id', $mappingIds)
                            ->update(['needUpdatePrice' => 0]);
                    } else {
                        throw new RuntimeException(
                            "Не удалось отправить пачку " . ($index + 1) . " из {$totalChunks} для seller {$sellerId}"
                        );
                    }
                } catch (ConnectionException $e) {
                    echo "🚨 Сетевая ошибка отправки пачки " . ($index + 1) . " из {$totalChunks} для seller {$sellerId}: {$e->getMessage()}\r\n";
                    throw $e;
                } catch (\Exception $e) {
                    echo "🚨 Ошибка отправки пачки " . ($index + 1) . " из {$totalChunks} для seller {$sellerId}: {$e->getMessage()}\r\n";
                    throw $e;
                }

                $processedBatches++;
            }
        }

        if ($reachedBatchLimit) {
            $remainingCount = SkuMapping::where('needUpdatePrice', 1)->count();
            echo "ℹ️ Осталось цен к обновлению: {$remainingCount}\n";
        }

        $remainingCount = SkuMapping::where('needUpdatePrice', 1)->count();
        $processedCount = max(0, $skuMappings->count() - $remainingCount);
        $this->notifyPriceUpdateFinished(
            $startedAt,
            $currentAttempt,
            $maxAttempts,
            $processedBatches,
            $processedCount,
            $remainingCount,
            $batchSize,
            $maxBatchesPerRun
        );

        self::dispatch(self::ACTION_UPDATE_PRICE, [])->onQueue(self::QUEUE_UPDATE_PRICE)->delay(now()->addHour());
    }

    private function buildPricePayloadForMapping(SkuMapping $skuMapping): PriceUpdatePayload
    {
        $sellPrice = $this->calculateSellPrice($skuMapping);

        $card = $skuMapping->card;
        if (!$card) {
            throw new RuntimeException('Не удалось получить карточку для skuMapping');
        }

        $sellerId = $card->sellerID;
        $nmID = $card->nmID;
        if (!$sellerId || !$nmID) {
            throw new RuntimeException('Не удалось получить sellerID или nmID');
        }

        return new PriceUpdatePayload(
            sellerId: (int)$sellerId,
            nmId: (int)$nmID,
            price: $sellPrice,
            mappingId: (int)$skuMapping->id,
        );
    }

    private function calculateSellPrice(SkuMapping $skuMapping): int
    {
        $calculatedPrice = $skuMapping->total_cost - ($skuMapping->total_cost * self::PRICE_MARGIN);
        if ($calculatedPrice < $skuMapping->wbPrice) {
            return (int)ceil($skuMapping->wbPrice + ($skuMapping->wbPrice * self::PRICE_MARGIN));
        }

        return (int)ceil($skuMapping->total_cost);
    }

    private function notifyPriceUpdateStarted(Carbon $startedAt, int $currentAttempt, int $maxAttempts): void
    {
        SystemNotification::create([
            'title' => 'Запущено обновление цен',
            'message' => "Джоба обновления цен стартовала (попытка {$currentAttempt}/{$maxAttempts}).",
            'level' => 'info',
            'source' => 'wb_update_price_job',
            'meta' => [
                'started_at' => $startedAt->toDateTimeString(),
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
            ],
        ]);
    }

    private function notifyPriceUpdateFinished(
        Carbon $startedAt,
        int $currentAttempt,
        int $maxAttempts,
        int $processedBatches,
        int $processedCount,
        int $remainingCount,
        int $batchSize,
        int $maxBatchesPerRun
    ): void {
        SystemNotification::create([
            'title' => 'Обновление цен завершено',
            'message' => "Попытка {$currentAttempt}/{$maxAttempts}. Обработано: {$processedCount}, осталось: {$remainingCount}, пачек за запуск: {$processedBatches}.",
            'level' => 'success',
            'source' => 'wb_update_price_job',
            'meta' => [
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => now()->toDateTimeString(),
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
                'processed_batches' => $processedBatches,
                'processed_count' => $processedCount,
                'remaining_count' => $remainingCount,
                'batch_size' => $batchSize,
                'max_batches_per_run' => $maxBatchesPerRun,
            ],
        ]);
    }

    private function buildCardListContext(array $params): ?CardListContext
    {
        $sellerId = (int)($params['seller_id'] ?? 0);
        if ($sellerId <= 0) {
            return null;
        }

        // Поддержка старого формата payload:
        // - sku -> sourceSku
        // - nmID -> queueWbSku
        $sourceSku = $params['sourceSku'] ?? ($params['sku'] ?? null);
        $queueWbSku = $params['queueWbSku'] ?? ($params['nmID'] ?? null);
        $settings = (array)($params['settings'] ?? []);

        return new CardListContext(
            sellerId: $sellerId,
            sourceSku: $sourceSku,
            queueWbSku: $queueWbSku,
            settings: $settings,
        );
    }

    private function buildPhotoUploadPayload(array $params): ?PhotoUploadPayload
    {
        $sellerId = (int)($params['seller_id'] ?? 0);
        $nmId = (int)($params['nmID'] ?? 0);
        $supplierId = (int)($params['supplierID'] ?? 0);
        if ($sellerId <= 0 || $nmId <= 0 || $supplierId <= 0) {
            return null;
        }

        return new PhotoUploadPayload(
            sellerId: $sellerId,
            nmId: $nmId,
            supplierId: $supplierId,
        );
    }
}
