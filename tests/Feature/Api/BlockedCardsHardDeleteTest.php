<?php

namespace Tests\Feature\Api;

use App\Models\Cards;
use App\Models\ProductQueue;
use App\Models\Sellers;
use App\Models\SellerWarehouse;
use App\Models\SellerWarehouseStockSnapshot;
use App\Models\SkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class BlockedCardsHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('hardDelete удаляет cards, skuMapping, product_queues и вызывает корзину WB')]
    public function test_hard_delete_purges_and_calls_wb_trash(): void
    {
        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::response([], 200),
        ]);

        $seller = $this->createSeller();
        $nmId = 55_555_555;
        $chrt = 9_001_002_003;

        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $nmId,
            'supplier' => 20,
            'supplierVendorCode' => 'SM-L-HARD-001-1',
            'vendorCode' => 'HARD001',
            'supplierName' => 'Sima',
            'productName' => 'X',
            'chrtID' => (string) $chrt,
            'photo' => null,
            'sku' => null,
        ]);

        SkuMapping::create([
            'origSku' => 'HARD001',
            'wbSku' => (string) $nmId,
        ]);

        ProductQueue::create([
            'sku' => (string) $nmId,
            'prefix' => null,
            'price' => null,
            'blocked' => 0,
        ]);

        $wh = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 77_777,
            'name' => 'W1',
        ]);

        SellerWarehouseStockSnapshot::create([
            'seller_warehouse_id' => $wh->id,
            'chrt_id' => $chrt,
            'amount' => 1,
            'is_positive' => true,
            'collected_at' => now(),
            'last_sent_to_wb_at' => null,
        ]);

        $response = $this->postJson('/api/blocked-cards/hardDelete', [
            'supplierVendorCodes' => ['SM-L-HARD-001-1'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.status', 'success')
            ->assertJsonPath('data.items.0.deleted_cards', 1);

        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        $this->assertSame(0, SkuMapping::count());
        $this->assertSame(0, ProductQueue::count());
        $this->assertSame(0, SellerWarehouseStockSnapshot::count());

        Http::assertSentCount(1);
    }

    #[TestDox('hardDelete для нескольких артикулов одного продавца — один HTTP-запрос в корзину WB с несколькими nmID')]
    public function test_hard_delete_single_wb_request_for_multiple_codes_same_seller(): void
    {
        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::response([], 200),
        ]);

        $seller = $this->createSeller();
        $codes = ['SM-L-MULTI-A-1', 'SM-L-MULTI-B-1', 'SM-L-MULTI-C-1'];
        $nmIds = [71_111_111, 72_222_222, 73_333_333];
        $vendorCodes = ['MULTIA', 'MULTIB', 'MULTIC'];

        foreach ($codes as $i => $svcCode) {
            Cards::create([
                'sellerID' => $seller->id,
                'nmID' => $nmIds[$i],
                'supplier' => 20,
                'supplierVendorCode' => $svcCode,
                'vendorCode' => $vendorCodes[$i],
                'supplierName' => 'Sima',
                'productName' => 'P'.$i,
                'chrtID' => null,
                'photo' => null,
                'sku' => null,
            ]);
            SkuMapping::create([
                'origSku' => $vendorCodes[$i],
                'wbSku' => (string) $nmIds[$i],
            ]);
        }

        $response = $this->postJson('/api/blocked-cards/hardDelete', [
            'supplierVendorCodes' => $codes,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.processed', 3);

        $this->assertSame(0, Cards::count());
        Http::assertSentCount(1);
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        $payload = $recorded[0][0]->data();
        $this->assertCount(3, $payload['nmIDs'] ?? []);
        sort($payload['nmIDs']);
        $this->assertSame($nmIds, array_values($payload['nmIDs']));
    }

    #[TestDox('hardDelete при ошибке WB не трогает локальные данные')]
    public function test_hard_delete_keeps_local_when_wb_fails(): void
    {
        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::response(['error' => true], 422),
        ]);

        $seller = $this->createSeller();
        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 66_666_666,
            'supplier' => 20,
            'supplierVendorCode' => 'SM-L-HARD-FAIL-1',
            'vendorCode' => 'HFAIL1',
            'supplierName' => 'Sima',
            'productName' => 'Y',
            'chrtID' => null,
            'photo' => null,
            'sku' => null,
        ]);

        SkuMapping::create([
            'origSku' => 'HFAIL1',
            'wbSku' => '66666666',
        ]);

        $response = $this->postJson('/api/blocked-cards/hardDelete', [
            'supplierVendorCodes' => ['SM-L-HARD-FAIL-1'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');

        $this->assertDatabaseHas('cards', ['id' => $card->id]);
        $this->assertSame(1, SkuMapping::count());
    }

    #[TestDox('hardDelete без артикулов — 422')]
    public function test_hard_delete_validation(): void
    {
        $response = $this->postJson('/api/blocked-cards/hardDelete', [
            'supplierVendorCodes' => [],
        ]);

        $response->assertStatus(422);
    }

    private function createSeller(): Sellers
    {
        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        return $seller;
    }
}
