<?php

namespace App\Services;

use App\Models\ProductQueue;
use Exception;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class SimService
{
    public static function fetchProductData($sid)
    {
        return Http::withHeaders(['Accept' => 'application/json'])
            ->get("https://www.sima-land.ru/api/v3/item/?sid={$sid}")
            ->json();
    }

    /**
     * @throws Exception
     */
    public static function validateResponse(array $response): bool
    {
        if (!isset($response['items'][0])) {
            throw new Exception('Invalid API response format');
        }
        $item = $response['items'][0];
        if (!self::calculateStockQuantity($item)) {
            throw new Exception('Amount is null');
        }
        if (self::checkMinQuantity($item)) {
            throw new Exception('Min quantity > 1');
        }
        return true;
    }


    public static function checkMinQuantity(array $item): bool
    {
        return (
            (isset($item['minimum_order_quantity']) && $item['minimum_order_quantity'] > 1) ||
            (isset($item['cart_min_diff']) && $item['cart_min_diff'] > 1) ||
            (isset($item['min_qty']) && $item['min_qty'] > 1)
        );
    }

    public static function calculateStockQuantity(array $item): int
    {
        if (isset($item['balance']) && $item['balance']) {
            return 5;
        }
        return isset($item['isEnough']) && $item['isEnough'] ? 5 : 0;
    }

    /**
     * @throws Exception
     */
    public static function getProductDimensions(array $item, float $productVolume, ?string $sku = null): array
    {
        $hasMissingDimension =
            !isset($item['depth']) || $item['depth'] === 0 ||
            !isset($item['width']) || $item['width'] === 0 ||
            !isset($item['height']) || $item['height'] === 0;
        if ($hasMissingDimension && $productVolume > 0) {
            $calculatedDimensions = self::calculateDimensionsCm($productVolume);
            $dimensions = [
                'depth' => $calculatedDimensions['height'],
                'length' => $calculatedDimensions['length'],
                'width' => $calculatedDimensions['width'],
                'weight_kg' => (isset($item['weight']) && $item['weight'] !== 0)
                    ? round($item['weight'] / 1000, 2)
                    : 1
            ];
            self::assertDimensionsWithinLimit($dimensions, $sku);
            return $dimensions;
        }
        $dimensions = [
            'depth' => (isset($item['depth']) && $item['depth'] !== 0) ? $item['depth'] : 10,
            'length' => (isset($item['width']) && $item['width'] !== 0) ? $item['width'] : 10,
            'width' => (isset($item['height']) && $item['height'] !== 0) ? $item['height'] : 10,
            'weight_kg' => (isset($item['weight']) && $item['weight'] !== 0)
                ? round($item['weight'] / 1000, 2)
                : 1
        ];
        self::assertDimensionsWithinLimit($dimensions, $sku);
        return $dimensions;
    }

    /**
     * @throws Exception
     */
    private static function assertDimensionsWithinLimit(array $dimensions, ?string $sku = null): void
    {
        $maxDimension = max($dimensions['depth'], $dimensions['length'], $dimensions['width']);
        if ($maxDimension > 100) {
            $skuLabel = $sku ? " для SKU {$sku}" : '';
            throw new Exception(
                "Товар{$skuLabel} помещен в карантин: одна из сторон больше 100 см " .
                "(depth={$dimensions['depth']}, length={$dimensions['length']}, width={$dimensions['width']})"
            );
        }
    }

    public static function calculateDimensionsCm(float $volume): array
    {
        $volumeCm3 = $volume * 1000;
        if ($volumeCm3 <= 0) {
            throw new InvalidArgumentException('Объем должен быть положительным числом');
        }
        $sideLength = pow($volumeCm3, 1 / 3);
        return [
            'length' => round($sideLength, 2),    // длина в см
            'width' => round($sideLength, 2),    // ширина в см
            'height' => round($sideLength, 2)     // высота в см
        ];
    }
}
