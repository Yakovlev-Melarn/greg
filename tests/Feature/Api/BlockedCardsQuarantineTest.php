<?php

namespace Tests\Feature\Api;

use App\Models\Cards;
use App\Models\ProductQueue;
use App\Models\Sellers;
use App\Models\SkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class BlockedCardsQuarantineTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Карантин при пустом cards.sku использует nmID как ключ product_queues')]
    public function test_quarantine_uses_nm_id_when_card_sku_is_null(): void
    {
        $seller = $this->createSeller();
        $nmId = 42_424_242;
        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $nmId,
            'supplier' => 1,
            'supplierVendorCode' => 'SM-L-999001-1',
            'vendorCode' => '999001',
            'supplierName' => 'Test',
            'productName' => 'Товар',
            'chrtID' => null,
            'photo' => null,
            'sku' => null,
        ]);

        SkuMapping::create([
            'origSku' => '999001',
            'wbSku' => (string) $nmId,
        ]);

        $response = $this->postJson('/api/blocked-cards/quarantine', [
            'supplierVendorCodes' => ['SM-L-999001-1'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.status', 'success')
            ->assertJsonPath('data.items.0.card.queueSku', (string) $nmId);

        $this->assertDatabaseHas('product_queues', [
            'sku' => (string) $nmId,
            'blocked' => 1,
        ]);
    }

    #[TestDox('При заполненном cards.sku в очередь попадает именно он')]
    public function test_quarantine_prefers_card_sku_when_present(): void
    {
        $seller = $this->createSeller();
        $wbSku = '12624007';
        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 88_888_888,
            'supplier' => 1,
            'supplierVendorCode' => 'WB-X-111222-1',
            'vendorCode' => '111222',
            'supplierName' => 'Test',
            'productName' => 'Товар 2',
            'chrtID' => null,
            'photo' => null,
            'sku' => $wbSku,
        ]);

        SkuMapping::create([
            'origSku' => '111222',
            'wbSku' => $wbSku,
        ]);

        $response = $this->postJson('/api/blocked-cards/quarantine', [
            'supplierVendorCodes' => ['WB-X-111222-1'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.card.queueSku', $wbSku);

        $this->assertDatabaseHas('product_queues', [
            'sku' => $wbSku,
            'blocked' => 1,
        ]);
    }

    #[TestDox('Неизвестный артикул возвращает not_found без записи в очередь')]
    public function test_unknown_code_returns_not_found(): void
    {
        $response = $this->postJson('/api/blocked-cards/quarantine', [
            'supplierVendorCodes' => ['UNKNOWN-CODE-XYZ'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.not_found', 1)
            ->assertJsonPath('data.items.0.status', 'not_found');

        $this->assertSame(0, ProductQueue::count());
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
