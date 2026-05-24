<?php

namespace Tests\Unit\Services;

use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Services\SimaSupplierAuditService;
use App\Services\WildberriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SimaSupplierAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(bool $onWb, int $totalQuantity): SimaSupplierAuditService
    {
        $wb = $this->createMock(WildberriesService::class);
        $wb->method('catalogAnyCardMatches')->willReturn($onWb);
        if ($onWb && $totalQuantity <= 5) {
            $wb->expects($this->never())->method('moveCardsToTrashBatchedWithRetry');
        }

        return new SimaSupplierAuditService($wb);
    }

    private function seedCardAndMapping(array $cardAttrs, array $mappingAttrs): array
    {
        $seller = new Sellers;
        $seller->name = 'Test Seller';
        $seller->wb_api_key = 'key';
        $seller->save();

        $card = Cards::query()->create(array_merge([
            'sellerID' => $seller->id,
            'supplier' => 20,
            'supplierName' => 'Sima-Land',
            'supplierVendorCode' => 'LC-S-111-1',
            'vendorCode' => '111222',
            'productName' => 'Test',
            'nmID' => 9_999_001,
        ], $cardAttrs));

        $mapping = SkuMapping::query()->create(array_merge([
            'origSku' => $card->vendorCode,
            'wbSku' => '12345678',
            'purchase_price' => 100,
            'wbPrice' => 200,
            'blocked' => false,
            'user_blocked' => false,
        ], $mappingAttrs));

        return [$seller, $card, $mapping];
    }

    #[TestDox('Sima дешевле WB — outcome sima_cheaper, user_blocked=1')]
    public function test_sima_cheaper_skips_wb_check(): void
    {
        [$seller, $card] = $this->seedCardAndMapping([], [
            'purchase_price' => 50,
            'wbPrice' => 200,
            'blocked' => false,
        ]);

        $wb = $this->createMock(WildberriesService::class);
        $wb->expects($this->never())->method('catalogAnyCardMatches');

        $service = new SimaSupplierAuditService($wb);
        $result = $service->processCard($card, $seller);

        $this->assertSame(SimaSupplierAuditService::OUTCOME_SIMA_CHEAPER, $result->outcome);
        $mapping = SkuMapping::query()->where('origSku', $card->vendorCode)->first();
        $this->assertFalse((bool) $mapping->blocked);
        $this->assertTrue((bool) $mapping->user_blocked);
    }

    #[TestDox('blocked=1, нет на WB — not_on_wb')]
    public function test_blocked_not_on_wb(): void
    {
        [$seller, $card] = $this->seedCardAndMapping([], ['blocked' => true]);
        $service = $this->makeService(false, 0);
        $result = $service->processCard($card, $seller);
        $this->assertSame(SimaSupplierAuditService::OUTCOME_NOT_ON_WB, $result->outcome);
    }

    #[TestDox('blocked=1, остаток > 5 — switched_to_wb')]
    public function test_blocked_switches_to_wb_when_stock_high(): void
    {
        Http::fake([
            'card.wb.ru/*' => Http::response([
                'products' => [['id' => 12345678, 'totalQuantity' => 10]],
            ]),
        ]);

        [$seller, $card] = $this->seedCardAndMapping([], ['blocked' => true]);
        $service = $this->makeService(true, 10);
        $result = $service->processCard($card, $seller);

        $this->assertSame(SimaSupplierAuditService::OUTCOME_SWITCHED_TO_WB, $result->outcome);
        $card->refresh();
        $this->assertSame(10, (int) $card->supplier);
        $this->assertSame('12345678', $card->vendorCode);
        $this->assertNotNull($card->supplier_change_reason);
    }

    #[TestDox('blocked=0, Sima дороже, остаток ≤5 — skipped_low_stock')]
    public function test_unblocked_expensive_low_stock_no_trash(): void
    {
        Http::fake([
            'card.wb.ru/*' => Http::response([
                'products' => [['id' => 12345678, 'totalQuantity' => 3]],
            ]),
        ]);

        [$seller, $card] = $this->seedCardAndMapping([], [
            'blocked' => false,
            'purchase_price' => 300,
            'wbPrice' => 100,
        ]);
        $service = $this->makeService(true, 3);
        $result = $service->processCard($card, $seller);

        $this->assertSame(SimaSupplierAuditService::OUTCOME_SKIPPED_LOW_STOCK, $result->outcome);
        $this->assertSame(20, (int) $card->fresh()->supplier);
    }

    #[TestDox('Нет SkuMapping — missing_mapping без user_blocked')]
    public function test_missing_mapping_dispatches_without_user_blocked(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $seller = new Sellers;
        $seller->name = 'S';
        $seller->wb_api_key = 'k';
        $seller->save();

        $card = Cards::query()->create([
            'sellerID' => $seller->id,
            'supplier' => 20,
            'supplierName' => 'Sima-Land',
            'supplierVendorCode' => 'LC-S-1-1',
            'vendorCode' => '999888',
            'productName' => 'X',
            'nmID' => 1,
        ]);

        $wb = $this->createMock(WildberriesService::class);
        $service = new SimaSupplierAuditService($wb);
        $result = $service->processCard($card, $seller);

        $this->assertSame(SimaSupplierAuditService::OUTCOME_MISSING_MAPPING, $result->outcome);
        $this->assertFalse($result->markMappingProcessed);
    }
}
