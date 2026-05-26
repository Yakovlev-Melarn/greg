<?php

namespace App\Services;

use App\Models\Cards;
use App\Models\SkuMapping;

/**
 * SimJob::calcPrice — только Sima-Land. Не запускать для карточек WB-каталога (supplier=10).
 */
class SimCalcPriceEligibility
{
    public const SUPPLIER_WB = 10;

    public const SUPPLIER_SIMA = 20;

    public function shouldRunCalcPrice(string $origSku): bool
    {
        $origSku = trim($origSku);

        return $origSku !== '' && ! $this->hasWbCatalogCardForOrigSku($origSku);
    }

    /**
     * Карточка WB (supplier=10), привязанная к origSku или wbSku из skuMapping.
     */
    private function hasWbCatalogCardForOrigSku(string $origSku): bool
    {
        $mapping = SkuMapping::query()->where('origSku', $origSku)->first(['wbSku']);

        return Cards::query()
            ->where('supplier', self::SUPPLIER_WB)
            ->where(function ($q) use ($origSku, $mapping) {
                $q->where('vendorCode', $origSku);
                $wbSku = trim((string) ($mapping->wbSku ?? ''));
                if ($wbSku !== '') {
                    $q->orWhere('vendorCode', $wbSku);
                }
            })
            ->exists();
    }
}
