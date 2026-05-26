<?php

namespace Tests\Unit\Services;

use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Services\SimCalcPriceEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SimCalcPriceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function createSeller(): Sellers
    {
        $seller = new Sellers;
        $seller->name = 'Test Seller';
        $seller->wb_api_key = 'key';
        $seller->save();

        return $seller;
    }

    private function createCard(Sellers $seller, array $attrs): Cards
    {
        return Cards::query()->create(array_merge([
            'sellerID' => $seller->id,
            'supplierVendorCode' => 'LC-S-111-1',
            'productName' => 'Test',
            'nmID' => 9_999_001,
        ], $attrs));
    }

    #[TestDox('calcPrice разрешён для Sima (supplier=20)')]
    public function test_allows_sima_supplier_card(): void
    {
        $seller = $this->createSeller();
        $origSku = '4406997';
        SkuMapping::create(['origSku' => $origSku, 'wbSku' => '999001']);
        $this->createCard($seller, [
            'vendorCode' => $origSku,
            'supplier' => SimCalcPriceEligibility::SUPPLIER_SIMA,
            'supplierName' => 'Sima-Land',
        ]);

        $this->assertTrue((new SimCalcPriceEligibility)->shouldRunCalcPrice($origSku));
    }

    #[TestDox('calcPrice запрещён при карточке WB (supplier=10) с vendorCode = origSku')]
    public function test_blocks_when_wb_card_vendor_code_matches_orig_sku(): void
    {
        $seller = $this->createSeller();
        $origSku = '4406997';
        SkuMapping::create(['origSku' => $origSku, 'wbSku' => '888777']);
        $this->createCard($seller, [
            'vendorCode' => $origSku,
            'supplier' => SimCalcPriceEligibility::SUPPLIER_WB,
            'supplierName' => 'WB',
        ]);

        $this->assertFalse((new SimCalcPriceEligibility)->shouldRunCalcPrice($origSku));
    }

    #[TestDox('calcPrice запрещён после перевода на WB: vendorCode = wbSku из mapping')]
    public function test_blocks_when_wb_card_vendor_code_matches_mapping_wb_sku(): void
    {
        $seller = $this->createSeller();
        $origSku = '1234567';
        $wbSku = '4406997';
        SkuMapping::create(['origSku' => $origSku, 'wbSku' => $wbSku]);
        $this->createCard($seller, [
            'vendorCode' => $wbSku,
            'supplier' => SimCalcPriceEligibility::SUPPLIER_WB,
            'supplierName' => 'WB',
        ]);

        $this->assertFalse((new SimCalcPriceEligibility)->shouldRunCalcPrice($origSku));
    }

    #[TestDox('calcPrice разрешён, если mapping есть, а карточки ещё нет (клонирование)')]
    public function test_allows_when_no_card_yet(): void
    {
        $origSku = '4406997';
        SkuMapping::create(['origSku' => $origSku, 'wbSku' => '999001']);

        $this->assertTrue((new SimCalcPriceEligibility)->shouldRunCalcPrice($origSku));
    }
}
