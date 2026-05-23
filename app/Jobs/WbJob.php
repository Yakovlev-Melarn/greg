<?php

namespace App\Jobs;

use App\DTO\Wb\CardListContext;
use App\DTO\Wb\PhotoUploadPayload;
use App\DTO\Wb\PriceUpdatePayload;
use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\Cards as CardsModel;
use App\Models\Sellers;
use App\Models\SellerWarehouse;
use App\Models\SellerWarehouseStockHistory;
use App\Models\SellerWarehouseStockSnapshot;
use App\Models\SkuMapping;
use App\Models\SystemNotification;
use App\Services\SimService;
use App\Services\WarehouseStockWbEligibility;
use App\Services\Wb\CardSyncScheduler;
use App\Services\WildberriesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Время уникального замка (сек.): сбор Sima — сотни HTTP-запросов, дольше минутного интервала планировщика.
     */
    public int $uniqueFor = 7200;

    private const ACTION_UPDATE_PRICE = 'updatePrice';

    private const ACTION_GET_CARD_LIST = 'getCardList';

    private const ACTION_UPLOAD_PHOTOS = 'uploadPhotos';

    private const ACTION_COLLECT_STOCKS = 'collectStocks';

    private const QUEUE_UPDATE_CARDS_PROCESS = 'updateCardsProcess';

    private const QUEUE_UPDATE_PRICE = 'updatePrice';

    public const QUEUE_STOCKS = 'wbStocks';

    private const PRICE_UPDATE_DEFAULT_BATCH_SIZE = 1000;

    private const PRICE_UPDATE_DEFAULT_MAX_BATCHES_PER_RUN = 20;

    private const PRICE_MARGIN = 0.25;

    private const STOCK_CHUNK_SIZE = 100;

    private const STOCK_UPDATE_CHUNK_SIZE = 1000;

    private const STOCK_MAX_AMOUNT = 5;

    private const SIMA_STOCK_CHUNK_SIZE = 50;

    /**
     * SQLite ограничивает число bound-переменных в одном запросе (часто 999).
     * Чанки для whereIn / upsert / insert при сохранении остатков по складу.
     */
    private const STOCK_SNAPSHOT_WHEREIN_CHUNK_SIZE = 200;

    private const STOCK_SNAPSHOT_UPSERT_CHUNK_SIZE = 50;

    private const STOCK_HISTORY_INSERT_CHUNK_SIZE = 70;

    private const STOCK_MARK_SENT_WHEREIN_CHUNK_SIZE = 200;

    public int $tries = 10;

    public int $timeout = 0;

    public function __construct(
        private readonly string $action,
        private readonly array $params = []
    ) {}

    public function handle(): void
    {
        self::{$this->action}($this->params);
    }

    /**
     * Один активный сбор остатков на склад — иначе cron каждую минуту ставит дубликаты, пока Sima не ответит.
     */
    public function uniqueId(): string
    {
        if ($this->action === self::ACTION_COLLECT_STOCKS && isset($this->params['warehouse_id'])) {
            return 'wb-collect-stocks-wh-'.(int) $this->params['warehouse_id'];
        }

        return 'wb-job-'.$this->action.'-'.sha1((string) json_encode($this->params));
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
            'message' => "Джоба завершилась с ошибкой после попытки {$currentAttempt}/{$maxAttempts}: ".$exception->getMessage(),
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
        if (! $context) {
            echo 'error seller_id is null';

            return;
        }

        $seller = Sellers::find($context->sellerId);
        if (! $seller) {
            Log::warning('WbJob getCardList skipped: seller not found', ['params' => $params]);

            return;
        }

        $selectiveCodes = $this->normalizeSupplierVendorCodes($params['supplier_vendor_codes'] ?? null);
        if ($selectiveCodes !== []) {
            $this->syncCardsBySupplierVendorCodes($seller, $selectiveCodes, $params);

            return;
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $result = $service->getCardList($context->settings);
        $cards = $result['data']['cards'] ?? [];
        $cursorData = $result['data']['cursor'] ?? [];
        $cursor = $cursorData['nmID'] ?? null;
        $updatedAt = $cursorData['updatedAt'] ?? null;
        $total = (int) ($cursorData['total'] ?? 0);

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
     * Только указанные supplierVendorCode (фильтр textSearch WB), без полного обхода каталога и без prune orphan.
     *
     * @param  list<string>  $codes
     */
    private function syncCardsBySupplierVendorCodes(Sellers $seller, array $codes, array $params): void
    {
        if (! empty($params['cards_sync_notify_start'])) {
            $this->notifyCardsSyncStarted($params, $seller);
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $processedCards = 0;

        foreach ($codes as $vendorCode) {
            $cursorNmId = null;
            $cursorUpdatedAt = null;

            do {
                $cursor = ['limit' => 100];
                if ($cursorNmId !== null) {
                    $cursor['nmID'] = $cursorNmId;
                }
                if ($cursorUpdatedAt !== null) {
                    $cursor['updatedAt'] = $cursorUpdatedAt;
                }

                $settings = [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => $cursor,
                        'filter' => [
                            'textSearch' => $vendorCode,
                            'withPhoto' => -1,
                        ],
                    ],
                ];

                $result = $service->getCardList($settings);
                $cards = $result['data']['cards'] ?? [];
                $cursorData = $result['data']['cursor'] ?? [];
                $cursorNmId = $cursorData['nmID'] ?? null;
                $cursorUpdatedAt = $cursorData['updatedAt'] ?? null;
                $total = (int) ($cursorData['total'] ?? 0);

                $processedCards += count($cards);
                $this->updateCard($cards, $seller, null, null, true);

                $hasMore = $total === 100;
                if ($hasMore && $cursorNmId === null && $cursorUpdatedAt === null) {
                    Log::warning('WbJob selective sync: пагинация оборвалась без курсора', [
                        'seller_id' => $seller->id,
                        'vendorCode' => $vendorCode,
                    ]);
                    break;
                }
            } while ($hasMore);
        }

        $this->notifyCardsSyncFinishedIfTracked($params, $seller, $processedCards);
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeSupplierVendorCodes($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $parts = preg_split('/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $raw = $parts;
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
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
     * После полного обхода WB: блокировка «висячих» skuMapping (с проверкой WB), подстановка sku с nmID.
     * Новые orphan_for_clone здесь не выставляются — см. комментарий в теле метода.
     */
    private function pruneLocalCardsMissingFromWbCatalog(Sellers $seller, array $params, CardListContext $context): void
    {
        if (! $this->shouldAccumulateWbNmIdsForOrphans($params, $context)) {
            return;
        }

        $wbSet = array_keys($params['wb_nm_ids_seen'] ?? []);
        // Полный обход каталога не должен порождать новых сирот: накопленный wb_nm_ids_seen может быть неполным
        // (пагинация, сбои API), а пометка orphan_for_clone здесь необратимо портит данные.
        $this->pruneLocalCardsRemovedFromWb($seller, $wbSet, markNewOrphans: false);
        $this->reconcileCardsWithSkuMappingAfterFullCatalog($seller, markNewOrphans: false);
        Log::info('WbJob full catalog prune: пропущена постановка orphan_for_clone для новых случаев (только backfill sku и блокировка mapping)', [
            'seller_id' => $seller->id,
            'wb_nm_ids_count' => count($wbSet),
        ]);
    }

    /**
     * После полного обхода WB: блокировка «висячих» skuMapping (с проверкой WB), подстановка sku с nmID, пометка непривязанных карточек с пустым sku как сирот.
     *
     * @param  bool  $markNewOrphans  при false не выставляется orphan_for_clone (режим полного обхода каталога).
     */
    private function reconcileCardsWithSkuMappingAfterFullCatalog(Sellers $seller, bool $markNewOrphans = true): void
    {
        $blockedMappings = $this->blockUnlinkedSkuMappingsAfterWbValidation($seller);
        if ($blockedMappings > 0) {
            Log::info('WbJob blocked skuMapping rows without matching cards (after WB validation)', [
                'seller_id' => $seller->id,
                'blocked_mappings' => $blockedMappings,
            ]);
        }

        $filled = $this->backfillNullCardSkuFromSkuMapping($seller);
        if ($filled > 0) {
            Log::info('WbJob backfilled cards.sku from skuMapping.wbSku', [
                'seller_id' => $seller->id,
                'updated_cards' => $filled,
            ]);
        }

        if (! $markNewOrphans) {
            return;
        }

        $markedOrphans = $this->trashUnmappedSupplierGt10CardsAndDelete($seller);
        if ($markedOrphans > 0) {
            Log::info('WbJob marked unmapped cards (supplier>10, sku empty) as orphan_for_clone instead of deleting', [
                'seller_id' => $seller->id,
                'marked_cards' => $markedOrphans,
            ]);
        }
    }

    /**
     * Дописывает cards.sku из skuMapping.wbSku для привязанных карточек с пустым sku.
     */
    private function backfillNullCardSkuFromSkuMapping(Sellers $seller): int
    {
        $sellerId = $seller->id;

        $n20 = DB::update(
            'UPDATE cards SET sku = (
                SELECT sm.wbSku FROM skuMapping sm WHERE sm.origSku = cards.vendorCode
                  AND (sm.blocked = 0 OR sm.blocked IS NULL) LIMIT 1
            )
            WHERE sellerID = ?
              AND supplier = 20
              AND sku IS NULL
              AND EXISTS (SELECT 1 FROM skuMapping sm WHERE sm.origSku = cards.vendorCode AND (sm.blocked = 0 OR sm.blocked IS NULL))',
            [$sellerId]
        );

        $n10 = DB::update(
            'UPDATE cards SET sku = (
                SELECT sm.wbSku FROM skuMapping sm WHERE sm.wbSku = cards.vendorCode
                  AND (sm.blocked = 0 OR sm.blocked IS NULL) LIMIT 1
            )
            WHERE sellerID = ?
              AND supplier = 10
              AND sku IS NULL
              AND EXISTS (SELECT 1 FROM skuMapping sm WHERE sm.wbSku = cards.vendorCode AND (sm.blocked = 0 OR sm.blocked IS NULL))',
            [$sellerId]
        );

        return (int) $n20 + (int) $n10;
    }

    /**
     * Карточки с supplier &gt; 10 (не только WB), без строки в skuMapping и с пустым sku — только пометка сироты (без корзины WB и без удаления).
     */
    private function trashUnmappedSupplierGt10CardsAndDelete(Sellers $seller): int
    {
        $marked = 0;

        CardsModel::query()
            ->where('sellerID', $seller->id)
            ->where('supplier', '>', 10)
            ->where(function ($q) {
                $q->whereNull('sku')->orWhere('sku', '');
            })
            ->orderBy('id')
            ->chunkById(50, function ($cards) use (&$marked) {
                foreach ($cards as $card) {
                    if ($this->cardHasMatchingSkuMapping($card)) {
                        continue;
                    }
                    if (! $this->cardSkuIsEmpty($card)) {
                        continue;
                    }
                    $nmId = (int) $card->nmID;
                    if ($nmId <= 0) {
                        Log::warning('WbJob skip orphan mark (supplier>10): card has no nmID', [
                            'card_id' => $card->id,
                            'seller_id' => $card->sellerID,
                        ]);

                        continue;
                    }
                    $card->orphan_for_clone = true;
                    $card->save();
                    $marked++;
                }
            });

        return $marked;
    }

    private function cardHasMatchingSkuMapping(CardsModel $card): bool
    {
        $supplier = (int) $card->supplier;
        $vc = (string) ($card->vendorCode ?? '');
        if ($vc === '') {
            return false;
        }
        if ($supplier === 20) {
            return SkuMapping::query()
                ->where('origSku', $vc)
                ->where(function ($q) {
                    $q->where('blocked', false)->orWhereNull('blocked');
                })
                ->exists();
        }
        if ($supplier === 10) {
            return SkuMapping::query()
                ->where('wbSku', $vc)
                ->where(function ($q) {
                    $q->where('blocked', false)->orWhereNull('blocked');
                })
                ->exists();
        }

        return SkuMapping::query()
            ->where(function ($q) use ($vc) {
                $q->where('origSku', $vc)->orWhere('wbSku', $vc);
            })
            ->where(function ($q) {
                $q->where('blocked', false)->orWhereNull('blocked');
            })
            ->exists();
    }

    /**
     * @param  list<int>  $wbNmIds
     * @param  bool  $markNewOrphans  при false не выставляется orphan_for_clone для карточек «не попали в wb_nm_ids_seen» (полный обход каталога).
     */
    private function pruneLocalCardsRemovedFromWb(Sellers $seller, array $wbNmIds, bool $markNewOrphans = true): void
    {
        $wbLookup = [];
        foreach ($wbNmIds as $id) {
            $wbLookup[(int) $id] = true;
        }

        $locals = CardsModel::query()
            ->where('sellerID', $seller->id)
            ->get();

        $removed = 0;
        $markedOrphans = 0;
        foreach ($locals as $card) {
            $nmId = (int) $card->nmID;
            if ($nmId === 0) {
                continue;
            }
            if (isset($wbLookup[$nmId])) {
                continue;
            }

            if ($this->cardSkuIsEmpty($card)) {
                if ($markNewOrphans) {
                    $card->orphan_for_clone = true;
                    $card->save();
                    $markedOrphans++;
                }

                continue;
            }

            if ($this->localCardStillPresentInWbSellerOrPublicCatalog($seller, $card)) {
                Log::info('WbJob pruneLocalCardsRemovedFromWb: пропуск удаления карточки — товар найден проверкой WB (supplierVendorCode / nmID)', [
                    'seller_id' => $seller->id,
                    'card_id' => $card->id,
                    'nmID' => $nmId,
                    'supplierVendorCode' => $card->supplierVendorCode,
                ]);

                continue;
            }

            DB::transaction(function () use ($card) {
                $this->blockSkuMappingsForCard($card);
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

        if ($markedOrphans > 0) {
            Log::info('WbJob marked local cards absent from WB catalog as orphan (empty sku), skipped delete', [
                'seller_id' => $seller->id,
                'marked_orphans' => $markedOrphans,
            ]);
        }
    }

    /**
     * Строки skuMapping без карточки в БД: не удаляем — блокируем только после проверки, что товара нет в кабинете WB (textSearch + basket).
     */
    private function blockUnlinkedSkuMappingsAfterWbValidation(Sellers $seller): int
    {
        $blocked = 0;
        SkuMapping::query()
            ->where(function ($q) {
                $q->where('blocked', false)->orWhereNull('blocked');
            })
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
            ->orderBy('id')
            ->chunkById(50, function ($mappings) use ($seller, &$blocked) {
                foreach ($mappings as $mapping) {
                    if ($this->skuMappingRowStillActiveInSellerCatalog($seller, $mapping)) {
                        continue;
                    }
                    $mapping->blocked = true;
                    $mapping->save();
                    $blocked++;
                }
            });

        return $blocked;
    }

    /**
     * Пагинация get/cards/list по textSearch; при ошибке API считаем «не уверены» и не блокируем строку skuMapping.
     *
     * @param  \Closure(array<string, mixed>): bool  $matches
     */
    private function sellerCatalogAnyCardMatches(Sellers $seller, string $textSearch, \Closure $matches): bool
    {
        $apiKey = trim((string) ($seller->wb_api_key ?? ''));
        if ($apiKey === '' || trim($textSearch) === '') {
            return false;
        }

        try {
            $service = new WildberriesService($apiKey, []);
            $cursorNmId = null;
            $cursorUpdatedAt = null;

            do {
                $cursor = ['limit' => 100];
                if ($cursorNmId !== null) {
                    $cursor['nmID'] = $cursorNmId;
                }
                if ($cursorUpdatedAt !== null) {
                    $cursor['updatedAt'] = $cursorUpdatedAt;
                }

                $settings = [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => $cursor,
                        'filter' => [
                            'textSearch' => $textSearch,
                            'withPhoto' => -1,
                        ],
                    ],
                ];

                $result = $service->getCardList($settings);
                if (($result['success'] ?? false) !== true) {
                    return true;
                }

                $payload = $result['data'] ?? [];
                $cards = $payload['cards'] ?? [];
                foreach ($cards as $card) {
                    if ($matches($card)) {
                        return true;
                    }
                }

                $cursorData = $payload['cursor'] ?? [];
                $cursorNmId = $cursorData['nmID'] ?? null;
                $cursorUpdatedAt = $cursorData['updatedAt'] ?? null;
                $total = (int) ($cursorData['total'] ?? 0);
                $hasMore = $total === 100 && ($cursorNmId !== null || $cursorUpdatedAt !== null);
                if ($hasMore && $cursorNmId === null && $cursorUpdatedAt === null) {
                    Log::warning('WbJob SkuMapping WB validation: pagination ended without cursor', [
                        'seller_id' => $seller->id,
                        'textSearch' => $textSearch,
                    ]);
                    break;
                }
            } while ($hasMore);

            return false;
        } catch (\Throwable $e) {
            Log::warning('WbJob SkuMapping WB validation: getCardList exception', [
                'seller_id' => $seller->id,
                'textSearch' => $textSearch,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function skuMappingRowStillActiveInSellerCatalog(Sellers $seller, SkuMapping $mapping): bool
    {
        $apiKey = trim((string) ($seller->wb_api_key ?? ''));
        if ($apiKey === '') {
            return true;
        }

        $orig = trim((string) $mapping->origSku);
        $wb = trim((string) $mapping->wbSku);
        $wbNmId = ($wb !== '' && ctype_digit($wb)) ? (int) $wb : 0;

        foreach (array_unique(array_filter([$orig, $wb])) as $needle) {
            $found = $this->sellerCatalogAnyCardMatches($seller, $needle, function (array $c) use ($orig, $wb, $wbNmId) {
                $vc = trim((string) ($c['vendorCode'] ?? ''));
                $nm = (int) ($c['nmID'] ?? 0);
                if ($orig !== '' && $vc === $orig) {
                    return true;
                }
                if ($wb !== '' && $vc === $wb) {
                    return true;
                }
                if ($wbNmId > 0 && $nm === $wbNmId) {
                    return true;
                }

                return false;
            });
            if ($found) {
                return true;
            }
        }

        if ($wbNmId > 0) {
            try {
                $info = WBContent::getCardInfo($wbNmId);
                if (is_array($info) && (! empty($info['id']) || ! empty($info['imt_name']))) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Перед удалением локальной карточки (nmID не попал в полный список обхода): не трогаем, если по supplierVendorCode / nmID товар ещё виден в WB.
     */
    private function localCardStillPresentInWbSellerOrPublicCatalog(Sellers $seller, CardsModel $card): bool
    {
        $nmId = (int) $card->nmID;
        if ($nmId > 0) {
            try {
                $info = WBContent::getCardInfo($nmId);
                if (is_array($info) && (! empty($info['id']) || ! empty($info['imt_name']))) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        $apiKey = trim((string) ($seller->wb_api_key ?? ''));
        if ($apiKey === '') {
            return true;
        }

        $supplierVendorCode = trim((string) ($card->supplierVendorCode ?? ''));
        if ($supplierVendorCode !== '') {
            if ($this->sellerCatalogAnyCardMatches($seller, $supplierVendorCode, function (array $c) use ($supplierVendorCode, $nmId) {
                $vc = trim((string) ($c['vendorCode'] ?? ''));
                $nm = (int) ($c['nmID'] ?? 0);

                return ($supplierVendorCode !== '' && $vc === $supplierVendorCode)
                    || ($nmId > 0 && $nm === $nmId);
            })) {
                return true;
            }
        }

        if ($nmId > 0) {
            if ($this->sellerCatalogAnyCardMatches($seller, (string) $nmId, function (array $c) use ($nmId) {
                return (int) ($c['nmID'] ?? 0) === $nmId;
            })) {
                return true;
            }
        }

        return false;
    }

    private function blockSkuMappingsForCard(CardsModel $card): void
    {
        $supplier = (int) $card->supplier;
        $vendorCode = (string) ($card->vendorCode ?? '');
        if ($vendorCode === '') {
            return;
        }

        $scope = function ($q): void {
            $q->where('blocked', false)->orWhereNull('blocked');
        };

        if ($supplier === 20) {
            SkuMapping::query()->where('origSku', $vendorCode)->where($scope)->update(['blocked' => true]);

            return;
        }

        if ($supplier === 10) {
            SkuMapping::query()->where('wbSku', $vendorCode)->where($scope)->update(['blocked' => true]);
        }
    }

    private function cardSkuIsEmpty(CardsModel $card): bool
    {
        $sku = $card->sku;
        if ($sku === null) {
            return true;
        }

        return trim((string) $sku) === '';
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
     * Один склад: сбор остатков (WB или Sima по маршруту склада) и опциональная отправка в WB.
     *
     * @throws ConnectionException
     */
    private function collectStocks(array $params): void
    {
        $warehouseId = $params['warehouse_id'] ?? null;
        if (! $warehouseId) {
            return;
        }

        $warehouse = SellerWarehouse::with('seller')->find($warehouseId);
        if (! $warehouse || ! $warehouse->seller) {
            return;
        }

        if (! $warehouse->stock_collect_enabled) {
            return;
        }

        $seller = $warehouse->seller;
        $supplierIds = $warehouse->effectiveStockSupplierIds();
        $simaVia = (string) ($warehouse->sima_stock_via ?? SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG);

        try {
            // Сразу обновляем время последнего запуска, иначе `stocks:dispatch-due` (каждую минуту)
            // снова поставит сбор, пока Sima обрабатывает тысячи SKU (долгий цикл запросов).
            $warehouse->forceFill([
                'stock_last_run_at' => now(),
                'stock_last_run_result' => [
                    'status' => 'running',
                    'stock_supplier_ids' => $supplierIds,
                    'sima_stock_via' => $simaVia,
                    'supplier' => SellerWarehouse::legacySupplierFromStockSupplierIds($supplierIds),
                ],
            ])->save();

            $stockRows = [];
            $perSupplier = [];
            foreach ($supplierIds as $supplierId) {
                $cards = $seller->cards()
                    ->where('supplier', $supplierId)
                    ->where('supplier', '>', 0)
                    ->get();
                $vendorToChrtMap = $this->buildVendorToChrtMap($cards);
                if ($supplierId === 20) {
                    $useSimaApi = $simaVia === SellerWarehouse::SIMA_STOCK_VIA_SIMA_API;
                    $rows = $useSimaApi
                        ? $this->fetchSimaStockRows($cards, $vendorToChrtMap)
                        : $this->fetchWbStockRows($cards, $vendorToChrtMap);
                } else {
                    $rows = $this->fetchWbStockRows($cards, $vendorToChrtMap);
                }
                $stockRows = $this->mergeWarehouseStockRowsLastWins($stockRows, $rows);
                $perSupplier[(string) $supplierId] = [
                    'cards' => $cards->count(),
                    'fetched' => count($rows),
                    'source' => $supplierId === 20
                        ? ($simaVia === SellerWarehouse::SIMA_STOCK_VIA_SIMA_API ? 'sima_api' : 'wb_catalog')
                        : 'wb_catalog',
                ];
            }

            $collectedAt = Carbon::now();
            $runKey = null;
            $persist = ['wb_candidates' => 0, 'rows_for_wb' => []];
            if ($stockRows !== []) {
                $runKey = (string) Str::uuid();
                $persist = $this->persistWarehouseStockSnapshotsAndHistory(
                    $warehouse,
                    $stockRows,
                    $collectedAt,
                    $runKey,
                );
            }

            $sentCount = 0;
            if (
                $warehouse->stock_send_to_wb
                && $persist['rows_for_wb'] !== []
                && $runKey !== null
            ) {
                $sentCount = $this->sendStockChunksToSingleWarehouse(
                    $seller,
                    $warehouse,
                    $persist['rows_for_wb'],
                );
                $this->markWarehouseStockHistorySent(
                    (int) $warehouse->id,
                    $runKey,
                    $persist['rows_for_wb'],
                );
            }

            $resultMeta = [
                'status' => 'ok',
                'stock_supplier_ids' => $supplierIds,
                'sima_stock_via' => $simaVia,
                'supplier' => SellerWarehouse::legacySupplierFromStockSupplierIds($supplierIds),
                'per_supplier' => $perSupplier,
                'fetched' => count($stockRows),
                'stored' => count($stockRows),
                'wb_candidates' => $persist['wb_candidates'],
                'sent' => $sentCount,
                'dry_run' => ! $warehouse->stock_send_to_wb,
            ];
            if ($runKey !== null) {
                $resultMeta['run_key'] = $runKey;
            }

            $warehouse->forceFill([
                'stock_last_run_at' => now(),
                'stock_last_run_result' => $resultMeta,
            ])->save();
        } catch (Throwable $e) {
            Log::error('WbJob collectStocks failed', [
                'warehouse_id' => $warehouseId,
                'message' => $e->getMessage(),
            ]);

            SystemNotification::create([
                'title' => 'Ошибка сбора остатков',
                'message' => sprintf(
                    'Склад #%s (%s): %s',
                    $warehouseId,
                    $seller->name,
                    $e->getMessage()
                ),
                'level' => 'error',
                'source' => 'wb_stock_collect',
                'meta' => [
                    'warehouse_id' => $warehouseId,
                    'seller_id' => $seller->id,
                ],
            ]);

            $warehouse->forceFill([
                'stock_last_run_at' => now(),
                'stock_last_run_result' => [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ],
            ])->save();
        }
    }

    /**
     * Ручной запуск (job:make updateWbStocks): все склады селлера с включённым сбором.
     *
     * @throws ConnectionException
     */
    private function updateStocks(array $params): void
    {
        if (empty($params['seller_id'])) {
            return;
        }

        $seller = Sellers::with('warehouses')->find($params['seller_id']);
        if (! $seller) {
            return;
        }

        foreach ($seller->warehouses as $warehouse) {
            if (! $warehouse->stock_collect_enabled) {
                continue;
            }
            $this->collectStocks(['warehouse_id' => $warehouse->id]);
        }
    }

    /**
     * Объединение строк остатков по chrtId: при коллизии побеждает последний источник (порядок в stock_supplier_ids).
     *
     * @param  list<array{chrtId: mixed, amount: int}>  $base
     * @param  list<array{chrtId: mixed, amount: int}>  $next
     * @return list<array{chrtId: mixed, amount: int}>
     */
    private function mergeWarehouseStockRowsLastWins(array $base, array $next): array
    {
        $byChrt = [];
        foreach ($base as $row) {
            $byChrt[(int) $row['chrtId']] = $row;
        }
        foreach ($next as $row) {
            $byChrt[(int) $row['chrtId']] = $row;
        }

        return array_values($byChrt);
    }

    private function buildVendorToChrtMap(Collection $cards): array
    {
        return $cards->pluck('chrtID', 'vendorCode')->toArray();
    }

    /**
     * Остатки через WB Content API (supplier 10 / дефолтный склад).
     *
     * @return list<array{chrtId: mixed, amount: int}>
     */
    private function fetchWbStockRows(Collection $cards, array $vendorToChrtMap): array
    {
        $vendorCodes = $cards->pluck('vendorCode')->toArray();
        $chunks = array_chunk($vendorCodes, self::STOCK_CHUNK_SIZE);
        $result = [];
        $total = count($chunks);
        echo 'Очередь на запрос из '.$total." пачек\n";
        foreach ($chunks as $chunk) {
            echo 'Осталось '.($total--)." пачек\n";
            $vendorCodeString = implode(';', $chunk);
            $stocks = WBContent::getAmounts($vendorCodeString);
            if ($stocks === false) {
                $stocks = [];
            }
            foreach ($stocks as $vendorCode => $quantity) {
                if (isset($vendorToChrtMap[$vendorCode])) {
                    $qty = (int) $quantity;
                    $result[] = [
                        'chrtId' => $vendorToChrtMap[$vendorCode],
                        'amount' => min(self::STOCK_MAX_AMOUNT, max(0, $qty)),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Остатки через Sima-Land API (supplier 20).
     *
     * @return list<array{chrtId: mixed, amount: int}>
     */
    private function fetchSimaStockRows(Collection $cards, array $vendorToChrtMap): array
    {
        $vendorCodes = $cards->pluck('vendorCode')->filter()->unique()->values()->all();
        $amounts = SimService::getAmountsBulk($vendorCodes, self::SIMA_STOCK_CHUNK_SIZE);
        $result = [];
        foreach ($vendorCodes as $vc) {
            if (! isset($vendorToChrtMap[$vc])) {
                continue;
            }
            $qty = (int) ($amounts[(string) $vc] ?? $amounts[$vc] ?? 0);
            $result[] = [
                'chrtId' => $vendorToChrtMap[$vc],
                'amount' => min(self::STOCK_MAX_AMOUNT, max(0, $qty)),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{chrtId: mixed, amount: int}>  $stockRows
     * @return array{wb_candidates: int, rows_for_wb: list<array{chrtId: int, amount: int}>}
     */
    private function persistWarehouseStockSnapshotsAndHistory(
        SellerWarehouse $warehouse,
        array $stockRows,
        Carbon $collectedAt,
        string $runKey,
    ): array {
        if ($stockRows === []) {
            return ['wb_candidates' => 0, 'rows_for_wb' => []];
        }

        $chrtIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) $r['chrtId'],
            $stockRows,
        )));

        $prevByChrt = collect();
        foreach (array_chunk($chrtIds, self::STOCK_SNAPSHOT_WHEREIN_CHUNK_SIZE) as $chrtChunk) {
            $batch = SellerWarehouseStockSnapshot::query()
                ->where('seller_warehouse_id', $warehouse->id)
                ->whereIn('chrt_id', $chrtChunk)
                ->get();
            foreach ($batch as $snap) {
                $prevByChrt[(string) $snap->chrt_id] = $snap;
            }
        }

        $now = Carbon::now();
        $snapshotUpsert = [];
        $historyInsert = [];
        $rowsForWb = [];

        foreach ($stockRows as $row) {
            $chrtId = (int) $row['chrtId'];
            $amount = (int) $row['amount'];
            $isPositive = $amount > 0;
            $prev = $prevByChrt->get((string) $chrtId);
            $prevPositive = $prev !== null ? (bool) $prev->is_positive : null;
            $eligible = WarehouseStockWbEligibility::shouldSyncToWb($prevPositive, $isPositive);
            if ($eligible) {
                $rowsForWb[] = ['chrtId' => $chrtId, 'amount' => $amount];
            }

            $snapshotUpsert[] = [
                'seller_warehouse_id' => $warehouse->id,
                'chrt_id' => $chrtId,
                'amount' => $amount,
                'is_positive' => $isPositive,
                'collected_at' => $collectedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $historyInsert[] = [
                'seller_warehouse_id' => $warehouse->id,
                'chrt_id' => $chrtId,
                'amount' => $amount,
                'is_positive' => $isPositive,
                'wb_eligible' => $eligible,
                'included_in_wb_batch' => false,
                'wb_sent_at' => null,
                'collected_at' => $collectedAt,
                'run_key' => $runKey,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($snapshotUpsert, $historyInsert): void {
            foreach (array_chunk($snapshotUpsert, self::STOCK_SNAPSHOT_UPSERT_CHUNK_SIZE) as $chunk) {
                SellerWarehouseStockSnapshot::query()->upsert(
                    $chunk,
                    ['seller_warehouse_id', 'chrt_id'],
                    ['amount', 'is_positive', 'collected_at', 'updated_at'],
                );
            }
            foreach (array_chunk($historyInsert, self::STOCK_HISTORY_INSERT_CHUNK_SIZE) as $chunk) {
                SellerWarehouseStockHistory::query()->insert($chunk);
            }
        });

        return [
            'wb_candidates' => count($rowsForWb),
            'rows_for_wb' => $rowsForWb,
        ];
    }

    /**
     * @param  list<array{chrtId: int, amount: int}>  $rowsForWb
     */
    private function markWarehouseStockHistorySent(int $warehouseId, string $runKey, array $rowsForWb): void
    {
        if ($rowsForWb === []) {
            return;
        }

        $chrtIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) $r['chrtId'],
            $rowsForWb,
        )));
        $sentAt = Carbon::now();

        foreach (array_chunk($chrtIds, self::STOCK_MARK_SENT_WHEREIN_CHUNK_SIZE) as $chrtChunk) {
            SellerWarehouseStockHistory::query()
                ->where('seller_warehouse_id', $warehouseId)
                ->where('run_key', $runKey)
                ->whereIn('chrt_id', $chrtChunk)
                ->update([
                    'included_in_wb_batch' => true,
                    'wb_sent_at' => $sentAt,
                ]);
        }

        foreach (array_chunk($chrtIds, self::STOCK_MARK_SENT_WHEREIN_CHUNK_SIZE) as $chrtChunk) {
            SellerWarehouseStockSnapshot::query()
                ->where('seller_warehouse_id', $warehouseId)
                ->whereIn('chrt_id', $chrtChunk)
                ->update(['last_sent_to_wb_at' => $sentAt]);
        }
    }

    /**
     * @param  list<array{chrtId: mixed, amount: int}>  $rows
     *
     * @throws ConnectionException
     */
    private function sendStockChunksToSingleWarehouse(Sellers $seller, SellerWarehouse $warehouse, array $rows): int
    {
        $service = new WildberriesService($seller->wb_api_key, []);
        $chunks = array_chunk($rows, self::STOCK_UPDATE_CHUNK_SIZE);
        $sent = 0;
        foreach ($chunks as $chunk) {
            $service->updateStocks((int) $warehouse->wb_warehouse_id, $chunk);
            $sent += count($chunk);
        }

        return $sent;
    }

    /**
     * @throws ConnectionException
     */
    private function uploadPhotos(array $params): void
    {
        $payload = $this->buildPhotoUploadPayload($params);
        if (! $payload) {
            Log::warning('WbJob uploadPhotos skipped: invalid payload', ['params' => $params]);

            return;
        }

        $seller = Sellers::find($payload->sellerId);
        if (! $seller) {
            Log::warning('WbJob uploadPhotos skipped: seller not found', ['params' => $params]);

            return;
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $basket = Helper::getBasketNumber($payload->supplierId);
        $info = WBContent::getCardInfo($payload->supplierId);
        $photoCount = (int) ($info['media']['photo_count'] ?? 0);
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
                ."/part{$basket['mid']}/{$payload->supplierId}/images/big/{$i}.webp";
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
        if (! empty($params['manual_photo_refresh']) && ! empty($data[0])) {
            $cardId = (int) ($params['card_id'] ?? 0);
            $cardQuery = CardsModel::query()
                ->where('sellerID', $payload->sellerId)
                ->where('nmID', $payload->nmId);
            if ($cardId > 0) {
                $cardQuery->where('id', $cardId);
            }
            $card = $cardQuery->first();
            if ($card) {
                $card->photo = (string) $data[0];
                $card->save();
            }
        }
    }

    private function updateCard(
        array $cardsData,
        Sellers $seller,
        int|string|null $sourceSku = null,
        int|string|null $queueWbSku = null,
        bool $allowPersistWithoutPhoto = false,
    ): void {
        foreach ($cardsData as $card) {
            $photo = '';
            if (! empty($card['photos'][0]) && is_array($card['photos'][0])) {
                $p0 = $card['photos'][0];
                $photo = (string) ($p0['c246x328'] ?? $p0['square'] ?? $p0['tm'] ?? '');
            }

            $supplierVendorCode = (string) ($card['vendorCode'] ?? '');
            if ($supplierVendorCode === '') {
                continue;
            }

            $nmId = (int) ($card['nmID'] ?? 0);

            if ($photo === '') {
                // Фото ещё не готовы: запускаем загрузку и откладываем повторный fetch карточки.
                // nmID карточки из списка — запасной источник для basket/getCardInfo (префиксы PL-T / нестандартный vendorCode в ответе WB).
                $queued = self::queuePhotoUploadAndFollowUpFetch(
                    (int) $seller->id,
                    $nmId,
                    $supplierVendorCode,
                    $sourceSku,
                    $queueWbSku,
                    $nmId > 0 ? $nmId : null,
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

                if (! $allowPersistWithoutPhoto) {
                    // Полный обход каталога: не сохраняем карточку до появления фото в списке.
                    continue;
                }

                // Выборочная синхронизация: сохраняем строку без превью — превью подтянется после uploadPhotos / повторного fetch.
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

            $cardModel = $seller->cards()->updateOrCreate(
                ['nmID' => $card['nmID']],
                $data
            );

            if ($cardModel->wb_created_at === null && ! empty($card['createdAt'])) {
                try {
                    $cardModel->wb_created_at = Carbon::parse($card['createdAt']);
                    $cardModel->save();
                } catch (\Throwable $e) {
                    Log::warning('WbJob updateCard: не удалось разобрать createdAt из WB', [
                        'seller_id' => $seller->id,
                        'nmID' => $card['nmID'] ?? null,
                        'createdAt' => $card['createdAt'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
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
        ?int $photoSourceFallbackNmId = null,
        bool $manualPhotoRefresh = false,
        ?int $cardId = null,
    ): bool {
        $photoSourceSupplierId = self::resolvePhotoSourceSupplierId(
            $supplierVendorCode,
            $sourceSku,
            $queueWbSku,
            $photoSourceFallbackNmId,
        );
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
            (new CardSyncScheduler)->dispatchFollowUpCardFetch(
                $sellerId,
                $sourceSku,
                $queueWbSku,
                $supplierVendorCode
            );
        }

        return true;
    }

    public static function resolvePhotoSourceSupplierId(
        string $supplierVendorCode,
        int|string|null $sourceSku = null,
        int|string|null $queueWbSku = null,
        ?int $photoSourceFallbackNmId = null,
    ): int {
        $supplierCode = strtoupper($supplierVendorCode[0] ?? '');

        // Sima-Land (артикулы S…, в т.ч. SM-L-…): фото в WB — по nm донора; в skuMapping донор = wbSku, не origSku.
        // Раньше приоритет у cards.sku (queueWbSku) шёл раньше mapping: если в sku попал origSku, basket тянул неверную карточку.
        if ($supplierCode === 'S') {
            $origCandidates = [];
            $fromArticul = trim((string) Helper::getVendorCode($supplierVendorCode));
            if ($fromArticul !== '') {
                $origCandidates[] = $fromArticul;
            }
            $src = trim((string) ($sourceSku ?? ''));
            if ($src !== '' && ! in_array($src, $origCandidates, true)) {
                $origCandidates[] = $src;
            }

            foreach ($origCandidates as $origKey) {
                $wbSkuFromMapping = SkuMapping::query()
                    ->where('origSku', $origKey)
                    ->where(function ($q) {
                        $q->where('blocked', false)->orWhereNull('blocked');
                    })
                    ->value('wbSku');
                if (! empty($wbSkuFromMapping) && (int) $wbSkuFromMapping > 0) {
                    return (int) $wbSkuFromMapping;
                }
            }

            if (! empty($queueWbSku) && (int) $queueWbSku > 0) {
                return (int) $queueWbSku;
            }

            if (! empty($sourceSku) && (int) $sourceSku > 0) {
                return (int) $sourceSku;
            }

            $embedded = (int) Helper::getVendorCode($supplierVendorCode);
            if ($embedded > 0) {
                return $embedded;
            }

            return 0;
        }

        // Для WB пытаемся взять SKU из vendorCode.
        if ($supplierCode === 'W') {
            $vendorSku = (int) Helper::getVendorCode($supplierVendorCode);
            if ($vendorSku > 0) {
                return $vendorSku;
            }
        }

        // Fallback: nmID донора из клонирования/копирования (queueWbSku) — источник фото каталога WB.
        // vendor_code из карточки (sourceSku) часто не совпадает с nmID; брать его первым ломало basket/getCardInfo для префиксов вроде LC-S-….
        if (! empty($queueWbSku) && (int) $queueWbSku > 0) {
            return (int) $queueWbSku;
        }

        if (! empty($sourceSku)) {
            return (int) $sourceSku;
        }

        // Артикулы вида LC-S-{nmID}-{package}: средний сегмент — nmID донора для basket/getCardInfo (выборочный sync и т.п.).
        if (substr_count($supplierVendorCode, '-') >= 2) {
            $embedded = (int) Helper::getVendorCode($supplierVendorCode);
            if ($embedded > 0) {
                return $embedded;
            }
        }

        if ($photoSourceFallbackNmId !== null && $photoSourceFallbackNmId > 0) {
            return $photoSourceFallbackNmId;
        }

        return 0;
    }

    private function updatePrice(): void
    {
        $startedAt = now();
        $currentAttempt = $this->attempts();
        $maxAttempts = $this->tries();
        $this->notifyPriceUpdateStarted($startedAt, $currentAttempt, $maxAttempts);

        $priceUpdateQueueQuery = SkuMapping::query()
            ->where('needUpdatePrice', 1)
            ->where(function ($q) {
                $q->where('blocked', false)->orWhereNull('blocked');
            });

        $skuMappings = (clone $priceUpdateQueueQuery)->with('card')->get();

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
                echo '🚨 Ошибка при подготовке цены: '.$e->getMessage()."\r\n";
            }
        }

        foreach ($groupedBySeller as $sellerId => $items) {
            $seller = Sellers::find($sellerId);
            if (! $seller) {
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
                            'Не удалось отправить пачку '.($index + 1)." из {$totalChunks} для seller {$sellerId}"
                        );
                    }
                } catch (ConnectionException $e) {
                    echo '🚨 Сетевая ошибка отправки пачки '.($index + 1)." из {$totalChunks} для seller {$sellerId}: {$e->getMessage()}\r\n";
                    throw $e;
                } catch (\Exception $e) {
                    if (str_contains($e->getMessage(), 'Specified prices and discounts are already set')) {
                        echo 'ℹ️ Пачка '.($index + 1)." из {$totalChunks} для seller {$sellerId} пропущена: цены уже установлены\r\n";
                        SkuMapping::whereIn('id', $mappingIds)
                            ->update(['needUpdatePrice' => 0]);
                        $processedBatches++;

                        continue;
                    }

                    echo '🚨 Ошибка отправки пачки '.($index + 1)." из {$totalChunks} для seller {$sellerId}: {$e->getMessage()}\r\n";
                    throw $e;
                }

                $processedBatches++;
            }
        }

        if ($reachedBatchLimit) {
            $remainingCount = (clone $priceUpdateQueueQuery)->count();
            echo "ℹ️ Осталось цен к обновлению: {$remainingCount}\n";
        }

        $remainingCount = (clone $priceUpdateQueueQuery)->count();
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
        if (! $card) {
            throw new RuntimeException('Не удалось получить карточку для skuMapping');
        }

        $sellerId = $card->sellerID;
        $nmID = $card->nmID;
        if (! $sellerId || ! $nmID) {
            throw new RuntimeException('Не удалось получить sellerID или nmID');
        }

        return new PriceUpdatePayload(
            sellerId: (int) $sellerId,
            nmId: (int) $nmID,
            price: $sellPrice,
            mappingId: (int) $skuMapping->id,
        );
    }

    private function calculateSellPrice(SkuMapping $skuMapping): int
    {
        $calculatedPrice = $skuMapping->total_cost - ($skuMapping->total_cost * self::PRICE_MARGIN);
        if ($calculatedPrice < $skuMapping->wbPrice) {
            return (int) ceil($skuMapping->wbPrice + ($skuMapping->wbPrice * self::PRICE_MARGIN));
        }

        return (int) ceil($skuMapping->total_cost);
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
        $sellerId = (int) ($params['seller_id'] ?? 0);
        if ($sellerId <= 0) {
            return null;
        }

        // Поддержка старого формата payload:
        // - sku -> sourceSku
        // - nmID -> queueWbSku
        $sourceSku = $params['sourceSku'] ?? ($params['sku'] ?? null);
        $queueWbSku = $params['queueWbSku'] ?? ($params['nmID'] ?? null);
        $settings = (array) ($params['settings'] ?? []);

        return new CardListContext(
            sellerId: $sellerId,
            sourceSku: $sourceSku,
            queueWbSku: $queueWbSku,
            settings: $settings,
        );
    }

    private function buildPhotoUploadPayload(array $params): ?PhotoUploadPayload
    {
        $sellerId = (int) ($params['seller_id'] ?? 0);
        $nmId = (int) ($params['nmID'] ?? 0);
        $supplierId = (int) ($params['supplierID'] ?? 0);
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
