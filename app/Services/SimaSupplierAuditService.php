<?php

namespace App\Services;

use App\DTO\SimaSupplierAuditCardResult;
use App\Exceptions\SimaSupplierAuditWbException;
use App\Jobs\SimJob;
use App\Libs\WBContent;
use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class SimaSupplierAuditService
{
    public const OUTCOME_MISSING_MAPPING = 'missing_mapping';

    public const OUTCOME_SIMA_CHEAPER = 'sima_cheaper';

    public const OUTCOME_NOT_ON_WB = 'not_on_wb';

    public const OUTCOME_SWITCHED_TO_WB = 'switched_to_wb';

    public const OUTCOME_TRASHED = 'trashed';

    public const OUTCOME_SKIPPED_LOW_STOCK = 'skipped_low_stock';

    public const OUTCOME_SKIPPED_OTHER = 'skipped_other';

    public const REASON_BLOCKED_WB_STOCK = 'Карточка заблокирована у Sima-Land; на Wildberries остаток > 5 — поставщик изменён на Wildberries';

    public const REASON_EXPENSIVE_WB_STOCK = 'Цена закупки Sima-Land выше или равна цене WB; на Wildberries остаток > 5 — поставщик изменён на Wildberries';

    private const STOCK_THRESHOLD = 5;

    private const SUPPLIER_WB = 10;

    public function __construct(
        private readonly WildberriesService $wbService,
    ) {}

    public static function forSeller(Sellers $seller): self
    {
        $apiKey = trim((string) ($seller->wb_api_key ?? ''));

        return new self(new WildberriesService($apiKey, []));
    }

    public function processCard(Cards $card, Sellers $seller): SimaSupplierAuditCardResult
    {
        $vendorCode = trim((string) ($card->vendorCode ?? ''));
        if ($vendorCode === '') {
            return new SimaSupplierAuditCardResult(
                self::OUTCOME_SKIPPED_OTHER,
                "⚠️ card_id={$card->id}: пустой vendorCode",
                ['skipped_other' => 1],
            );
        }

        $mapping = SkuMapping::query()->where('origSku', $vendorCode)->first();
        if ($mapping === null) {
            SimJob::dispatch('calcPrice', ['sid' => $vendorCode])->onQueue('updateCardsProcess');

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_MISSING_MAPPING,
                "⚠️ card_id={$card->id} vendor={$vendorCode}: нет SkuMapping, поставлен SimJob calcPrice",
                ['missing_mapping' => 1],
                markMappingProcessed: false,
            );
        }

        $blocked = (bool) $mapping->blocked;
        if (! $blocked && $this->isSimaCheaperThanWb($mapping)) {
            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_SIMA_CHEAPER,
                "✅ card_id={$card->id} vendor={$vendorCode}: Sima дешевле WB — без изменений",
                ['sima_cheaper' => 1],
            );
        }

        $supplierVendorCode = trim((string) ($card->supplierVendorCode ?? ''));
        if ($supplierVendorCode === '') {
            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_SKIPPED_OTHER,
                "⚠️ card_id={$card->id}: пустой supplierVendorCode",
                ['skipped_other' => 1],
            );
        }

        $onWb = $this->cardExistsInSellerCatalog($supplierVendorCode, (int) ($card->nmID ?? 0));
        if (! $onWb) {
            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_NOT_ON_WB,
                "✅ card_id={$card->id} {$supplierVendorCode}: нет в каталоге WB — blocked=0, user_blocked=1",
                ['not_on_wb' => 1],
            );
        }

        $wbSku = trim((string) ($mapping->wbSku ?? ''));
        if ($wbSku === '') {
            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_SKIPPED_OTHER,
                "⚠️ card_id={$card->id}: пустой wbSku в SkuMapping",
                ['skipped_other' => 1],
            );
        }

        $totalQuantity = $this->fetchTotalQuantity($wbSku);
        if ($totalQuantity > self::STOCK_THRESHOLD) {
            $reason = $blocked ? self::REASON_BLOCKED_WB_STOCK : self::REASON_EXPENSIVE_WB_STOCK;
            $this->switchCardToWbSupplier($card, $mapping, $reason);
            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_SWITCHED_TO_WB,
                "✅ card_id={$card->id} wbSku={$wbSku} qty={$totalQuantity}: supplier→WB (10)",
                ['switched_to_wb' => 1],
            );
        }

        if ($blocked) {
            $nmId = (int) ($card->nmID ?? 0);
            if ($nmId <= 0) {
                $this->finalizeMappingFlags($mapping);

                return new SimaSupplierAuditCardResult(
                    self::OUTCOME_SKIPPED_OTHER,
                    "⚠️ card_id={$card->id}: нет nmID для корзины",
                    ['skipped_other' => 1],
                );
            }

            $trashErr = $this->wbService->moveCardsToTrashBatchedWithRetry([$nmId]);
            if ($trashErr !== null) {
                throw new SimaSupplierAuditWbException("Trash failed: {$trashErr}");
            }

            $this->finalizeMappingFlags($mapping);

            return new SimaSupplierAuditCardResult(
                self::OUTCOME_TRASHED,
                "✅ card_id={$card->id} nmID={$nmId} qty={$totalQuantity}: отправлено в корзину WB",
                ['trashed' => 1],
            );
        }

        $this->finalizeMappingFlags($mapping);

        return new SimaSupplierAuditCardResult(
            self::OUTCOME_SKIPPED_LOW_STOCK,
            "✅ card_id={$card->id} wbSku={$wbSku} qty={$totalQuantity}: остаток ≤5, supplier не меняем",
            ['skipped_low_stock' => 1],
        );
    }

    private function isSimaCheaperThanWb(SkuMapping $mapping): bool
    {
        $purchase = $mapping->purchase_price;
        $wbPrice = $mapping->wbPrice;
        if ($purchase === null || $wbPrice === null) {
            return false;
        }

        return (float) $purchase < (float) $wbPrice;
    }

    private function cardExistsInSellerCatalog(string $supplierVendorCode, int $nmId = 0): bool
    {
        $needle = trim($supplierVendorCode);
        if ($needle === '') {
            return false;
        }

        $found = $this->wbService->catalogAnyCardMatches($needle, static function (array $c) use ($needle, $nmId): bool {
            $vc = trim((string) ($c['vendorCode'] ?? ''));
            $nm = (int) ($c['nmID'] ?? 0);

            return ($needle !== '' && $vc === $needle)
                || ($nmId > 0 && $nm === $nmId);
        });
        if ($found) {
            return true;
        }

        if ($nmId > 0) {
            return $this->wbService->catalogAnyCardMatches((string) $nmId, static function (array $c) use ($nmId): bool {
                return (int) ($c['nmID'] ?? 0) === $nmId;
            });
        }

        return false;
    }

    /**
     * @throws SimaSupplierAuditWbException
     */
    private function fetchTotalQuantity(string $wbSku): int
    {
        try {
            $detail = WBContent::getDetail($wbSku);
        } catch (ConnectionException $e) {
            throw new SimaSupplierAuditWbException('WBContent getDetail: '.$e->getMessage(), 0, $e);
        }

        if ($detail === null) {
            throw new SimaSupplierAuditWbException("WBContent getDetail: empty response for {$wbSku}");
        }

        return (int) ($detail['totalQuantity'] ?? 0);
    }

    private function switchCardToWbSupplier(Cards $card, SkuMapping $mapping, string $reason): void
    {
        $wbSku = trim((string) ($mapping->wbSku ?? ''));
        if ($wbSku === '') {
            Log::warning('SimaSupplierAudit: cannot switch supplier without wbSku', ['card_id' => $card->id]);

            return;
        }

        if ((int) $card->supplier !== self::SUPPLIER_WB) {
            $card->supplier = self::SUPPLIER_WB;
            $card->supplierName = 'WB';
            $card->vendorCode = $wbSku;
            $card->supplier_change_reason = $reason;
            $card->supplier_changed_at = now();
            $card->save();
        }
    }

    private function finalizeMappingFlags(SkuMapping $mapping): void
    {
        $mapping->blocked = false;
        $mapping->user_blocked = true;
        $mapping->save();
    }
}
