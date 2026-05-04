<?php

namespace App\Services;

/**
 * Решение, нужно ли включать строку остатка в PUT к WB при политике «положительный ↔ положительный — не слать».
 */
final class WarehouseStockWbEligibility
{
    /**
     * @param  bool|null  $previousWasPositive  null — предыдущего снимка не было
     */
    public static function shouldSyncToWb(?bool $previousWasPositive, bool $currentIsPositive): bool
    {
        if ($previousWasPositive === null) {
            return true;
        }

        if ($previousWasPositive && $currentIsPositive) {
            return false;
        }

        if (! $previousWasPositive && ! $currentIsPositive) {
            return false;
        }

        return true;
    }
}
