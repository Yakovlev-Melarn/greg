<?php

namespace App\Services;

/**
 * Формулы совпадают с {@see \App\Jobs\SimJob::calcPrice} — единая точка для джобы и UI.
 */
final class SkuPriceRecalculationService
{
    public const WB_COMMISSION_RATE = 0.38;

    public const TAX_RATE = 0.06;

    public const FULFILLMENT_COST = 53;

    /**
     * @param  float  $profitMargin  Доля наценки к закупке (например 0.17 = 17%)
     * @return array{selling_price: float, total_cost: float, wb_commission: float, fulfillment_cost: int, tax: float, net_profit: float}
     */
    public static function calculateFromPurchaseAndLogistics(
        float $purchasePrice,
        float $logisticsCost,
        float $profitMargin
    ): array {
        $sellingPrice = self::calculateSellingPrice($purchasePrice, $logisticsCost, $profitMargin);
        $totalCost = $sellingPrice + $logisticsCost + self::FULFILLMENT_COST;
        $wbCommission = $totalCost * self::WB_COMMISSION_RATE;
        $tax = $totalCost * self::TAX_RATE;
        $netProfit = self::calculateNetProfit(
            $totalCost,
            $purchasePrice,
            $logisticsCost,
            self::FULFILLMENT_COST,
            $wbCommission,
            $tax
        );

        return [
            'selling_price' => round($sellingPrice, 2),
            'total_cost' => round($totalCost, 2),
            'wb_commission' => round($wbCommission, 2),
            'fulfillment_cost' => self::FULFILLMENT_COST,
            'tax' => round($tax, 2),
            'net_profit' => round($netProfit, 2),
        ];
    }

    private static function calculateSellingPrice(
        float $purchasePrice,
        float $logisticsCost,
        float $profitMargin
    ): float {
        $desiredProfit = $purchasePrice * (1 + $profitMargin);
        $fixedExpenses = $logisticsCost + self::FULFILLMENT_COST;

        return ($desiredProfit + $fixedExpenses) / (1 - self::WB_COMMISSION_RATE - self::TAX_RATE);
    }

    private static function calculateNetProfit(
        float $totalCost,
        float $purchasePrice,
        float $logisticsCost,
        float $fulfillmentCost,
        float $wbCommission,
        float $tax
    ): float {
        return $totalCost - (
            $purchasePrice +
            $logisticsCost +
            $fulfillmentCost +
            $wbCommission +
            $tax
        );
    }
}
