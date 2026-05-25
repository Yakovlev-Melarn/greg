<?php

namespace App\Jobs;

use App\Models\SkuMapping;
use App\Services\SimaUnmappedSkuCleanup;
use App\Services\SimService;
use App\Services\SkuPriceRecalculationService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SimJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const MIN_PROFIT_MARGIN = 0.17;

    public int $tries = 10;

    public int $timeout = 0;

    public array $backoff = [30, 120, 300, 600];

    public function __construct(
        private readonly string $action,
        private readonly array $params = []
    ) {}

    public function handle(): void
    {
        $this->{$this->action}($this->params);
    }

    public function cleanArticle($article): string
    {
        $candidates = SimService::normalizeSidCandidates((string) $article);

        return $candidates[0] ?? '';
    }

    private function calcPrice($params): array
    {
        $origSid = (string) ($params['sid'] ?? '');

        try {
            $sidCandidates = SimService::normalizeSidCandidates($origSid);
            $sid = $sidCandidates[0] ?? '';
            if ($sid === '') {
                echo "❌ calcPrice: не указан sid\n";

                return ['error' => 'missing_sid'];
            }

            $prior = SkuMapping::query()->where('origSku', $origSid)->first();
            if ($prior !== null && ($prior->blocked || $prior->user_blocked)) {
                echo "⏭️ origSku {$sid}: запись заблокирована, calcPrice пропущен\n";

                return ['skipped' => true, 'reason' => 'blocked'];
            }

            echo "🚀 Начало выполнения джобы\n";
            echo "🔍 Получение данных о товаре (sid: {$sid})\n";
            if (count($sidCandidates) > 1) {
                echo 'ℹ️ Варианты sid: '.implode(', ', $sidCandidates)."\n";
            }
            $response = SimService::fetchProductDataResolvingSid($origSid);
            echo "✅ Валидация ответа\n";
            SimService::validateResponse($response);
            $item = $response['items'][0];
            echo "📊 Проверка минимального количества\n";
            if (SimService::checkMinQuantity($item)) {
                return ['stock_quantity' => -1];
            }
            echo "📏 Получение размеров товара\n";
            $dimensions = SimService::getProductDimensions($item, $item['product_volume'] ?? 0, (string) $params['sid']);
            echo "📦 Расчет количества на складе\n";
            $stockQuantity = SimService::calculateStockQuantity($item);
            echo "🚚 Расчет логистики\n";
            $productVolume = ($item['product_volume'] ?? 1) ?: 1;
            $logisticsCost = $this->calculateLogistics($productVolume);
            echo "💰 Расчет закупочной цены\n";
            $purchasePrice = $item['price'];
            echo '🎯 Расчет цены продажи (наценка '.(self::MIN_PROFIT_MARGIN * 100)."% к закупке)\n";
            $priceBlock = SkuPriceRecalculationService::calculateFromPurchaseAndLogistics(
                $purchasePrice,
                $logisticsCost,
                self::MIN_PROFIT_MARGIN
            );
            $data = array_merge([
                'purchase_price' => $purchasePrice,
                'logistics_cost' => round($logisticsCost, 2),
                'stock_quantity' => $stockQuantity,
                'depth' => $item['depth'],
                'length' => $item['width'],
                'width' => $item['height'],
                'weight_kg' => round($item['weight'] / 1000, 2),
            ], $priceBlock, $dimensions);
            echo "🔄 Обновление или создание записи\n";
            $cleanup = new SimaUnmappedSkuCleanup;
            $mapping = $cleanup->findMappingBySid($origSid);
            if (! $cleanup->mappingHasWbSku($mapping)) {
                $purged = $cleanup->purgeByOrigSku($origSid);
                $keysHint = implode(', ', $purged['keys']);
                echo "🗑️ origSku {$origSid}: нет wbSku (ключи: {$keysHint}) — удалено карточек Sima: {$purged['cards_deleted']}, "
                    ."строк skuMapping: {$purged['mapping_deleted']}\n";

                return [
                    'purged' => true,
                    'reason' => 'no_wb_sku',
                    'cards_deleted' => $purged['cards_deleted'],
                    'mapping_deleted' => $purged['mapping_deleted'],
                ];
            }

            $mapping->fill(array_merge($data, ['blocked' => false]));
            $mapping->save();
            echo "✅ Джоба успешно завершена\n";
        } catch (ConnectionException $e) {
            // Temporary API/network issue: rethrow to let queue retry.
            echo '🌐 Временная ошибка соединения: '.$e->getMessage()."\n";
            throw $e;
        } catch (QueryException $e) {
            if ($this->isMissingWbSkuConstraintViolation($e)) {
                $purged = (new SimaUnmappedSkuCleanup)->purgeByOrigSku($origSid);
                echo "🗑️ origSku {$origSid}: ошибка NOT NULL wbSku — удалено карточек Sima: {$purged['cards_deleted']}, "
                    ."строк skuMapping: {$purged['mapping_deleted']}\n";

                return [
                    'purged' => true,
                    'reason' => 'no_wb_sku',
                    'cards_deleted' => $purged['cards_deleted'],
                    'mapping_deleted' => $purged['mapping_deleted'],
                ];
            }

            echo '🚨 Произошла ошибка: '.$e->getMessage()."\n";

            return $this->handleError($e);
        } catch (Exception $e) {
            echo '🚨 Произошла ошибка: '.$e->getMessage()."\n";
            if ($e->getMessage() === 'Invalid API response format') {
                // API occasionally returns broken/empty payloads under network pressure.
                // Treat this as transient and let queue retries handle it.
                throw $e;
            }

            $msg = $e->getMessage();

            if (
                $msg === 'Amount is null'
                || $this->isPermanentCalcPriceFailure($e)
                || $msg === 'Product not found'
            ) {
                $this->blockSkuMappingAfterPermanentFailure($origSid, $msg);

                return [
                    'blocked' => true,
                    'reason' => $msg,
                ];
            }

            return $this->handleError($e);
        }
        echo "🎉 Все операции выполнены успешно\n";

        return ['success' => true];
    }

    /**
     * Ошибки Sima-Land / размеров, после которых повторный calcPrice бессмысленен до ручного разбора.
     */
    private function isMissingWbSkuConstraintViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'skuMapping.wbSku')
            || str_contains($message, 'NOT NULL constraint failed: skuMapping.wbSku');
    }

    private function isPermanentCalcPriceFailure(Exception $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'помещен в карантин')
            || $msg === 'Min quantity > 1';
    }

    /**
     * Блокирует skuMapping, чтобы не ставить calcPrice снова (очередь retry-empty, и т.д.).
     */
    private function blockSkuMappingAfterPermanentFailure(string $sid, string $reason): void
    {
        if ($sid === '') {
            return;
        }

        $updated = SkuMapping::query()
            ->where('origSku', $sid)
            ->update([
                'blocked' => true,
                'needUpdatePrice' => false,
            ]);

        if ($updated > 0) {
            echo "🛑 origSku {$sid}: {$reason} — skuMapping заблокирован, пересчёт цен отключён\n";

            return;
        }

        echo "⚠️ origSku {$sid}: постоянная ошибка ({$reason}), строка skuMapping не найдена — блокировка не записана\n";
    }

    private function calculateLogistics(float $volume): float
    {
        $coefficient = 2.0; // 200%
        if ($volume >= 1) {
            return (46 + ($volume - 1) * 14) * $coefficient;
        }
        if ($volume <= 0.2) {
            $rate = 23;
        } elseif ($volume <= 0.4) {
            $rate = 26;
        } elseif ($volume <= 0.6) {
            $rate = 29;
        } elseif ($volume <= 0.8) {
            $rate = 30;
        } else {
            $rate = 32;
        }

        return $rate * $coefficient;
    }

    protected function handleError(Exception $exception): array
    {
        return [
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ],
        ];
    }
}
