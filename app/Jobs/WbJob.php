<?php

namespace App\Jobs;

use App\DTO\Wb\CardListContext;
use App\DTO\Wb\PhotoUploadPayload;
use App\DTO\Wb\PriceUpdatePayload;
use App\Libs\Helper;
use App\Libs\WBContent;
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

        // Обновляем/сохраняем карточки только после того, как фото уже появилось в WB.
        $this->updateCard($cards, $seller, $context->sourceSku, $context->queueWbSku);
        if ($total == 100) {
            // Пагинация WB: если получили полный лимит, запрашиваем следующую страницу.
            self::dispatch(self::ACTION_GET_CARD_LIST, [
                'seller_id' => $context->sellerId,
                'sourceSku' => $context->sourceSku,
                'queueWbSku' => $context->queueWbSku,
                'settings' => [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => [
                            'limit' => 100,
                            'updatedAt' => $updatedAt,
                            'nmID' => $cursor
                        ],
                        'filter' => ['withPhoto' => -1]
                    ]
                ]
            ])->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS);
        }
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
        $stockData = $this->fetchStockQuantities($cards, $vendorToChrtMap);
        $this->sendStockUpdates($seller, $stockData);
    }

    private function getSellerCards(Sellers $seller): Collection
    {
        return $seller->cards()
            ->where('supplier', 10)
            ->get();
    }

    private function buildVendorToChrtMap(Collection $cards): array
    {
        return $cards->pluck('chrtID', 'vendorCode')->toArray();
    }

    private function fetchStockQuantities(Collection $cards, array $vendorToChrtMap): array
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
                    $result[] = [
                        'chrtId' => $chrtID,
                        'amount' => $quantity > self::STOCK_MAX_AMOUNT ? self::STOCK_MAX_AMOUNT : 0
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
        $chunks = array_chunk($stockData, self::STOCK_UPDATE_CHUNK_SIZE);
        $service = new WildberriesService($seller->wb_api_key, []);
        $total = count($chunks);
        echo "Очередь на отправку из " . $total . " пачек\n";
        foreach ($chunks as $chunk) {
            $service->updateStocks($seller->wb_warehouse_id, $chunk);
            echo "Осталось " . ($total--) . " пачек\n";
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
        $service->uploadPhotos($payload->nmId, $data);
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
                $photoSourceSupplierId = $this->resolvePhotoSourceSupplierId($supplierVendorCode, $sourceSku, $queueWbSku);
                if ($photoSourceSupplierId > 0) {
                    self::dispatch(self::ACTION_UPLOAD_PHOTOS, [
                        'supplierID' => $photoSourceSupplierId,
                        'nmID' => (int)$card['nmID'],
                        'seller_id' => $seller->id,
                    ])->onQueue(self::QUEUE_UPDATE_CARDS_PROCESS);

                    // Re-fetch this exact card after media sync and save only when photo appears.
                    (new CardSyncScheduler())->dispatchFollowUpCardFetch(
                        sellerId: $seller->id,
                        sourceSku: $sourceSku,
                        queueWbSku: $queueWbSku,
                        supplierVendorCode: $supplierVendorCode
                    );
                } else {
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

    private function resolvePhotoSourceSupplierId(
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
