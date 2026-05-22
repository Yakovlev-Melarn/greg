<?php

namespace Tests\Feature\Api;

use App\Models\Cards;
use App\Models\SellerWarehouse;
use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SellerWarehouseStockRoutingTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('warehouseStore отклоняет пересечение stock_supplier_ids с другим складом селлера')]
    public function test_warehouse_store_rejects_supplier_overlap(): void
    {
        $seller = Sellers::create([
            'name' => 'Shop',
            'wb_api_key' => 'key',
        ]);
        SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 401,
            'name' => 'A',
            'supplier' => null,
            'stock_supplier_ids' => [10],
            'sima_stock_via' => 'wb_catalog',
            'stock_collect_enabled' => false,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $response = $this->postJson('/api/sellers/warehouseStore', [
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 402,
            'name' => 'B',
            'stock_supplier_ids' => [10, 20],
            'sima_stock_via' => 'sima_api',
            'stock_collect_enabled' => false,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stock_supplier_ids']);
    }

    #[TestDox('warehouseZeroStocks отклоняет поставщика, не входящего в маршрут склада')]
    public function test_warehouse_zero_stocks_rejects_unknown_supplier_for_warehouse(): void
    {
        $seller = Sellers::create([
            'name' => 'Shop',
            'wb_api_key' => 'k',
        ]);
        $wh = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 501,
            'name' => 'Main',
            'supplier' => null,
            'stock_supplier_ids' => [10],
            'sima_stock_via' => 'wb_catalog',
            'stock_collect_enabled' => false,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $this->postJson('/api/sellers/warehouseZeroStocks', [
            'warehouse_id' => $wh->id,
            'supplier_ids' => [20],
        ])->assertStatus(422);
    }

    #[TestDox('warehouseZeroStocks отправляет нули в WB при успешном ответе API')]
    public function test_warehouse_zero_stocks_calls_wb_api(): void
    {
        Http::fake([
            'https://marketplace-api.wildberries.ru/api/v3/stocks/*' => Http::response([], 200),
        ]);

        $seller = Sellers::create([
            'name' => 'Shop',
            'wb_api_key' => 'token',
        ]);
        $wh = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 777,
            'name' => 'Main',
            'supplier' => 20,
            'stock_supplier_ids' => [10, 20],
            'sima_stock_via' => 'wb_catalog',
            'stock_collect_enabled' => false,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 100,
            'supplier' => 10,
            'supplierName' => 'WB',
            'productName' => 'P',
            'chrtID' => '111',
            'vendorCode' => 'v1',
        ]);
        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 101,
            'supplier' => 20,
            'supplierName' => 'Sima',
            'productName' => 'P2',
            'chrtID' => '222',
            'vendorCode' => 'v2',
        ]);

        $response = $this->postJson('/api/sellers/warehouseZeroStocks', [
            'warehouse_id' => $wh->id,
            'supplier_ids' => [10],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sent', 1);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), 'marketplace-api.wildberries.ru/api/v3/stocks/777');
        });
    }

    #[TestDox('list с with_warehouses отдаёт stock_supplier_ids и sima_stock_via')]
    public function test_seller_list_includes_warehouse_routing_fields(): void
    {
        $seller = Sellers::create([
            'name' => 'Shop',
            'wb_api_key' => 'k',
        ]);
        SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 601,
            'name' => 'W',
            'supplier' => 20,
            'stock_supplier_ids' => [10, 20],
            'sima_stock_via' => 'sima_api',
            'stock_collect_enabled' => true,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $response = $this->postJson('/api/sellers/list', ['with_warehouses' => 1]);

        $response->assertOk();
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertSame([10, 20], $data[0]['warehouses'][0]['stock_supplier_ids']);
        $this->assertSame('sima_api', $data[0]['warehouses'][0]['sima_stock_via']);
    }
}
