<?php

namespace Tests\Feature\Api;

use App\Models\SellerWarehouse;
use App\Models\SellerWarehouseStockHistory;
use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SellerWarehouseStockHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('warehouseStockHistory возвращает последние записи по складу')]
    public function test_warehouse_stock_history_endpoint(): void
    {
        $seller = Sellers::create([
            'name' => 'Shop',
            'wb_api_key' => 'key',
        ]);
        $wh = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 101,
            'name' => 'Main',
            'supplier' => null,
            'stock_collect_enabled' => true,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $t = now()->subMinute();
        SellerWarehouseStockHistory::create([
            'seller_warehouse_id' => $wh->id,
            'chrt_id' => 555,
            'amount' => 2,
            'is_positive' => true,
            'wb_eligible' => true,
            'included_in_wb_batch' => false,
            'wb_sent_at' => null,
            'collected_at' => $t,
            'run_key' => '00000000-0000-4000-8000-000000000001',
        ]);

        $response = $this->postJson('/api/sellers/warehouseStockHistory', [
            'warehouse_id' => $wh->id,
            'limit' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('items.0.chrt_id', 555)
            ->assertJsonPath('items.0.amount', 2)
            ->assertJsonPath('items.0.wb_eligible', true)
            ->assertJsonPath('runs_summary.0.positions', 1)
            ->assertJsonPath('runs_summary.0.positive', 1)
            ->assertJsonPath('runs_summary.0.wb_eligible', 1)
            ->assertJsonPath('runs_summary.0.wb_sent', 0);
    }
}
