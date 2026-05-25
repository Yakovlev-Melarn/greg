<?php

namespace Tests\Unit\Services;

use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Services\SimaUnmappedSkuCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SimaUnmappedSkuCleanupTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('purgeForCard удаляет текущую карточку по id даже при другом origSku в mapping')]
    public function test_purge_for_card_deletes_by_id(): void
    {
        $seller = new Sellers;
        $seller->name = 'S';
        $seller->wb_api_key = 'k';
        $seller->save();

        $origSku = '10874017';
        $fullVendor = 'LC-S-'.$origSku.'-1';

        SkuMapping::query()->create([
            'origSku' => $fullVendor,
            'wbSku' => '9990001',
            'purchase_price' => 100,
        ]);

        $card = Cards::query()->create([
            'sellerID' => $seller->id,
            'nmID' => 1001,
            'supplier' => 20,
            'supplierName' => 'Sima-Land',
            'supplierVendorCode' => $fullVendor,
            'vendorCode' => $origSku,
            'productName' => 'Test',
        ]);

        $result = (new SimaUnmappedSkuCleanup)->purgeForCard($card);

        $this->assertGreaterThanOrEqual(1, $result['cards_deleted']);
        $this->assertSame(1, $result['mapping_deleted']);
        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('skuMapping', ['origSku' => $fullVendor]);
    }

    #[TestDox('purgeByOrigSku удаляет карточки Sima и skuMapping по sid')]
    public function test_purge_deletes_cards_and_mapping(): void
    {
        $seller = new Sellers;
        $seller->name = 'S';
        $seller->wb_api_key = 'k';
        $seller->save();

        $origSku = '10387038';

        SkuMapping::query()->create([
            'origSku' => $origSku,
            'wbSku' => '9990001',
            'purchase_price' => 100,
        ]);

        Cards::query()->create([
            'sellerID' => $seller->id,
            'nmID' => 1001,
            'supplier' => 20,
            'supplierName' => 'Sima-Land',
            'supplierVendorCode' => 'LC-S-'.$origSku.'-1',
            'vendorCode' => $origSku,
            'productName' => 'Test',
        ]);

        $result = (new SimaUnmappedSkuCleanup)->purgeByOrigSku($origSku);

        $this->assertSame(1, $result['cards_deleted']);
        $this->assertSame(1, $result['mapping_deleted']);
        $this->assertDatabaseMissing('skuMapping', ['origSku' => $origSku]);
    }

    #[TestDox('findMappingForCard находит строку по vendorCode sid при origSku = полный артикул')]
    public function test_find_mapping_by_resolved_keys(): void
    {
        $full = 'LC-S-555-1';
        $sid = '555';
        SkuMapping::query()->create([
            'origSku' => $full,
            'wbSku' => '888',
            'purchase_price' => 1,
        ]);

        $card = new Cards([
            'vendorCode' => $sid,
            'supplierVendorCode' => $full,
        ]);

        $found = (new SimaUnmappedSkuCleanup)->findMappingForCard($card);
        $this->assertNotNull($found);
        $this->assertSame($full, $found->origSku);
    }
}
