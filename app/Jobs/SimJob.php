<?php

namespace App\Jobs;

use App\Models\SkuMapping;
use App\Services\SimService;
use App\Services\SkuPriceRecalculationService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SimJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const MIN_PROFIT_MARGIN = 0.17;

    public int $tries = 10;

    public int $timeout = 3600;

    public array $backoff = [30, 120, 300, 600];

    public function __construct(
        private readonly string $action,
        private readonly array $params = []
    ) {}

    public function handle(): void
    {
        self::{$this->action}($this->params);
    }

    private function calcPrice($params): array
    {
        try {
            echo "🚀 Начало выполнения джобы\n";
            echo "🔍 Получение данных о товаре\n";
            $response = SimService::fetchProductData($params['sid']);
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
            SkuMapping::updateOrCreate(
                ['origSku' => $params['sid']],
                $data
            );
            echo "✅ Джоба успешно завершена\n";
        } catch (ConnectionException $e) {
            // Temporary API/network issue: rethrow to let queue retry.
            echo '🌐 Временная ошибка соединения: '.$e->getMessage()."\n";
            throw $e;
        } catch (Exception $e) {
            echo '🚨 Произошла ошибка: '.$e->getMessage()."\n";
            if ($e->getMessage() === 'Invalid API response format') {
                // API occasionally returns broken/empty payloads under network pressure.
                // Treat this as transient and let queue retries handle it.
                throw $e;
            }

            return $this->handleError($e);
        }
        echo "🎉 Все операции выполнены успешно\n";

        return ['success' => true];
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
