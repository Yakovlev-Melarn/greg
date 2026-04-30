<?php

namespace App\Jobs;

use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Models\SystemNotification;
use App\Services\WildberriesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $timeout = 3600;

    public function __construct(
        private readonly string $action,
        private readonly array  $params = []
    )
    {
    }

    public function handle(): void
    {
        self::{$this->action}($this->params);
    }

    public function tries(): int
    {
        return $this->action === 'updatePrice' ? 5 : $this->tries;
    }

    public function backoff(): array|int
    {
        return $this->action === 'updatePrice'
            ? [30, 120, 300, 600]
            : 0;
    }

    public function failed(Throwable $exception): void
    {
        if ($this->action !== 'updatePrice') {
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
    private function getCardList($params): void
    {
        if ($params['seller_id']) {
            // New explicit flow fields:
            // - sourceSku: original supplier SKU (e.g. Sima sid)
            // - queueWbSku: temporary WB sku from product queue before real card nmID is known
            // Backward compatibility:
            // - sku -> sourceSku
            // - nmID -> queueWbSku
            $sourceSku = empty($params['sourceSku']) ? ($params['sku'] ?? null) : $params['sourceSku'];
            $queueWbSku = empty($params['queueWbSku']) ? ($params['nmID'] ?? null) : $params['queueWbSku'];
            $seller = Sellers::find($params['seller_id']);
            $service = new WildberriesService($seller->wb_api_key, []);
            $result = $service->getCardList($params['settings']);
            $cursor = $result['data']['cursor']['nmID'];
            $updatedAt = $result['data']['cursor']['updatedAt'];
            $total = $result['data']['cursor']['total'];
            $this->updateCard($result['data']['cards'], $seller, $sourceSku, $queueWbSku);
            if ($total == 100) {
                self::dispatch('getCardList', [
                    'seller_id' => $params['seller_id'],
                    'sourceSku' => $sourceSku,
                    'queueWbSku' => $queueWbSku,
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
                ])->onQueue('updateCardsProcess');
            }
        } else {
            echo 'error seller_id is null';
        }
    }

    /**
     * @throws ConnectionException
     */
    private function updateStocks($params): void
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
        $chunks = array_chunk($vendorCodes, 100);
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
                        'amount' => $quantity > 5 ? 5 : 0
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
        $chunks = array_chunk($stockData, 1000);
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
    private function uploadPhotos($params): void
    {
        $seller = Sellers::find($params['seller_id']);
        if (!$seller) {
            Log::warning('WbJob uploadPhotos skipped: seller not found', ['params' => $params]);
            return;
        }

        $nmId = (int)($params['nmID'] ?? 0);
        $sourceSupplierId = (int)($params['supplierID'] ?? 0);
        if ($nmId <= 0 || $sourceSupplierId <= 0) {
            Log::warning('WbJob uploadPhotos skipped: invalid nmID/supplierID', [
                'nmID' => $nmId,
                'supplierID' => $sourceSupplierId,
                'params' => $params,
            ]);
            return;
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $basket = Helper::getBasketNumber($sourceSupplierId);
        $info = WBContent::getCardInfo($sourceSupplierId);
        $photoCount = (int)($info['media']['photo_count'] ?? 0);
        if ($photoCount <= 0) {
            Log::warning('WbJob uploadPhotos skipped: source has no photos', [
                'sourceSupplierId' => $sourceSupplierId,
                'nmID' => $nmId,
            ]);
            return;
        }

        $data = [];
        for ($i = 1; $i <= $photoCount; $i++) {
            $data[] = "https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}"
                . "/part{$basket['mid']}/{$sourceSupplierId}/images/big/{$i}.webp";
        }
        $service->uploadPhotos($nmId, $data);
    }

    private function updateCard($cardsData, Sellers $seller, $sourceSku = null, $queueWbSku = null): void
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
                $photoSourceSupplierId = $this->resolvePhotoSourceSupplierId($supplierVendorCode, $sourceSku, $queueWbSku);
                if ($photoSourceSupplierId > 0) {
                    self::dispatch('uploadPhotos', [
                        'supplierID' => $photoSourceSupplierId,
                        'nmID' => (int)$card['nmID'],
                        'seller_id' => $seller->id,
                    ])->onQueue('updateCardsProcess');

                    // Re-fetch this exact card after media sync and save only when photo appears.
                    self::dispatch('getCardList', [
                        'seller_id' => $seller->id,
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
                    ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
                } else {
                    Log::warning('WbJob updateCard skipped: photo source id is not resolved', [
                        'seller_id' => $seller->id,
                        'supplierVendorCode' => $supplierVendorCode,
                        'sourceSku' => $sourceSku,
                        'queueWbSku' => $queueWbSku,
                        'nmID' => $card['nmID'] ?? null,
                    ]);
                }

                // Do not save card until photo is available.
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

    private function resolvePhotoSourceSupplierId(string $supplierVendorCode, $sourceSku = null, $queueWbSku = null): int
    {
        $supplierCode = strtoupper((string)($supplierVendorCode[0] ?? ''));

        // Sima-Land clone flow:
        // photo source must be WB queue SKU when available.
        if ($supplierCode === 'S') {
            if (!empty($queueWbSku)) {
                return (int)$queueWbSku;
            }
            if (!empty($sourceSku)) {
                return (int)$sourceSku;
            }
            return (int)Helper::getVendorCode($supplierVendorCode);
        }

        // Wildberries supplier: photo source is vendor code payload SKU if possible.
        if ($supplierCode === 'W') {
            $vendorSku = (int)Helper::getVendorCode($supplierVendorCode);
            if ($vendorSku > 0) {
                return $vendorSku;
            }
        }

        // Fallbacks for legacy calls.
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

        $skuMappings = SkuMapping::with('card')
            ->where('needUpdatePrice', 1)
            ->get();

        $groupedBySeller = [];
        $processedBatches = 0;
        $reachedBatchLimit = false;
        $batchSize = max(1, min((int) env('WB_UPDATE_PRICE_BATCH_SIZE', 1000), 1000));
        $maxBatchesPerRun = max(1, (int) env('WB_UPDATE_PRICE_MAX_BATCHES_PER_RUN', 20));

        foreach ($skuMappings as $skuMapping) {
            $calculatedPrice = $skuMapping->total_cost - ($skuMapping->total_cost * 0.25);
            if ($calculatedPrice < $skuMapping->wbPrice) {
                $sellPrice = $skuMapping->wbPrice + ($skuMapping->wbPrice * 0.25);
            } else {
                $sellPrice = $skuMapping->total_cost;
            }
            $sellPrice = ceil($sellPrice);

            try {
                $card = $skuMapping->card;
                if (!$card) {
                    throw new \Exception('Не удалось получить карточку для skuMapping');
                }

                $sellerId = $card->sellerID;
                $nmID = $card->nmID;

                if (!$sellerId || !$nmID) {
                    throw new \Exception('Не удалось получить sellerID или nmID');
                }

                $groupedBySeller[$sellerId][] = [
                    'mappingId' => $skuMapping->id,
                    'priceData' => [
                        'nmID' => $nmID,
                        'price' => $sellPrice,
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

        self::dispatch('updatePrice', [])->onQueue('updatePrice')->delay(now()->addHour());
    }
}
