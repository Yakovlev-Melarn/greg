<?php

namespace Tests\Feature\Jobs;

use App\Jobs\WbJob;
use App\Models\SellerWarehouse;
use App\Models\SellerWarehouseStockHistory;
use App\Models\SellerWarehouseStockSnapshot;
use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use Tests\TestCase;

class WarehouseStockPersistSqliteTest extends TestCase
{
    use RefreshDatabase;

    private function invokePersist(
        SellerWarehouse $warehouse,
        array $stockRows,
        Carbon $collectedAt,
        string $runKey,
    ): array {
        $job = new WbJob('collectStocks', []);
        $m = new ReflectionMethod(WbJob::class, 'persistWarehouseStockSnapshotsAndHistory');
        $m->setAccessible(true);

        /** @var array{wb_candidates: int, rows_for_wb: list<array{chrtId: int, amount: int}>} $out */
        $out = $m->invoke($job, $warehouse, $stockRows, $collectedAt, $runKey);

        return $out;
    }

    private function invokeMarkSent(int $warehouseId, string $runKey, array $rowsForWb): void
    {
        $job = new WbJob('collectStocks', []);
        $m = new ReflectionMethod(WbJob::class, 'markWarehouseStockHistorySent');
        $m->setAccessible(true);
        $m->invoke($job, $warehouseId, $runKey, $rowsForWb);
    }

    #[TestDox('Сохранение большого числа снимков и истории не превышает лимит переменных SQLite')]
    public function test_large_stock_persist_succeeds_on_sqlite(): void
    {
        $seller = Sellers::create([
            'name' => 'Тест',
            'wb_api_key' => 'key',
        ]);
        $warehouse = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 501,
            'name' => 'Склад',
            'supplier' => null,
            'stock_collect_enabled' => true,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $n = 600;
        $stockRows = [];
        for ($i = 0; $i < $n; $i++) {
            $stockRows[] = [
                'chrtId' => 10_000_000 + $i,
                'amount' => ($i % 6 === 0) ? 0 : min(5, ($i % 6)),
            ];
        }

        $runKey = (string) Str::uuid();
        $collectedAt = Carbon::now();

        $out = $this->invokePersist($warehouse, $stockRows, $collectedAt, $runKey);

        $this->assertSame($n, SellerWarehouseStockSnapshot::query()->where('seller_warehouse_id', $warehouse->id)->count());
        $this->assertSame($n, SellerWarehouseStockHistory::query()->where('seller_warehouse_id', $warehouse->id)->count());
        $this->assertGreaterThan(0, $out['wb_candidates']);
    }

    #[TestDox('Пометка отправки в WB чанкуется по chrt_id для SQLite')]
    public function test_mark_sent_chunks_where_in_on_sqlite(): void
    {
        $seller = Sellers::create([
            'name' => 'Тест 2',
            'wb_api_key' => 'key',
        ]);
        $warehouse = SellerWarehouse::create([
            'seller_id' => $seller->id,
            'wb_warehouse_id' => 502,
            'name' => 'Склад 2',
            'supplier' => null,
            'stock_collect_enabled' => true,
            'stock_send_to_wb' => false,
            'stock_frequency_minutes' => 30,
        ]);

        $runKey = (string) Str::uuid();
        $t = Carbon::now();
        $rowsForWb = [];
        for ($i = 0; $i < 350; $i++) {
            $chrt = 20_000_000 + $i;
            $rowsForWb[] = ['chrtId' => $chrt, 'amount' => 3];
            SellerWarehouseStockHistory::create([
                'seller_warehouse_id' => $warehouse->id,
                'chrt_id' => $chrt,
                'amount' => 3,
                'is_positive' => true,
                'wb_eligible' => true,
                'included_in_wb_batch' => false,
                'wb_sent_at' => null,
                'collected_at' => $t,
                'run_key' => $runKey,
            ]);
        }

        $this->invokeMarkSent((int) $warehouse->id, $runKey, $rowsForWb);

        $sent = SellerWarehouseStockHistory::query()
            ->where('seller_warehouse_id', $warehouse->id)
            ->where('run_key', $runKey)
            ->where('included_in_wb_batch', true)
            ->whereNotNull('wb_sent_at')
            ->count();

        $this->assertSame(350, $sent);
    }
}
