<?php

namespace Tests\Feature\Api;

use App\Jobs\WbJob;
use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CardsUploadPhotosTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('uploadPhotos API возвращает ошибку если карточка не найдена')]
    public function test_upload_photos_returns_error_when_card_missing(): void
    {
        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => 999_999,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Карточка не найдена');
    }

    #[TestDox('Sima-Land: при несовпадении vendor_code донора с origSku — корзина WB и сирота')]
    public function test_sima_land_mismatch_trashes_and_marks_orphan(): void
    {
        Bus::fake();

        $seller = $this->createSeller();
        $donorNm = 12_624_007;
        $ourNm = 88_888_888;
        $origSku = '999001';

        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $ourNm,
            'supplier' => 20,
            'supplierVendorCode' => "S-1-{$origSku}-1",
            'vendorCode' => $origSku,
            'supplierName' => 'Sima-Land',
            'productName' => 'Тест',
            'chrtID' => null,
            'photo' => null,
            'sku' => (string) $donorNm,
        ]);

        SkuMapping::create([
            'origSku' => $origSku,
            'wbSku' => (string) $donorNm,
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, 'wbbasket.ru') && str_contains($url, '/info/ru/card.json')) {
                return Http::response(['vendor_code' => 'WRONG_SKU', 'id' => 1], 200);
            }
            if (str_contains($url, 'card.wb.ru/cards/v4/detail')) {
                return Http::response([
                    'products' => [['id' => 1, 'vendorCode' => 'WRONG_SKU']],
                ], 200);
            }
            if (str_contains($url, 'content-api.wildberries.ru/content/v2/cards/delete/trash')) {
                return Http::response([], 200);
            }

            return Http::response(['unexpected' => true], 404);
        });

        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => $card->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $card->refresh();
        $this->assertTrue((bool) $card->orphan_for_clone);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($ourNm) {
            if (! str_contains($request->url(), 'delete/trash')) {
                return false;
            }
            $body = $request->data();
            $nmIds = $body['nmIDs'] ?? [];

            return is_array($nmIds) && in_array($ourNm, $nmIds, true);
        });

        Bus::assertNothingDispatched();
    }

    #[TestDox('Sima-Land: при совпадении vendor_code донора с origSku — ставим джобу uploadPhotos')]
    public function test_sima_land_match_dispatches_upload_job(): void
    {
        Bus::fake();

        $seller = $this->createSeller();
        $donorNm = 12_624_007;
        $ourNm = 77_777_777;
        $origSku = '100002';

        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $ourNm,
            'supplier' => 20,
            'supplierVendorCode' => "S-1-{$origSku}-1",
            'vendorCode' => $origSku,
            'supplierName' => 'Sima-Land',
            'productName' => 'Тест 2',
            'chrtID' => null,
            'photo' => null,
            'sku' => (string) $donorNm,
        ]);

        SkuMapping::create([
            'origSku' => $origSku,
            'wbSku' => (string) $donorNm,
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'wbbasket.ru') && str_contains($request->url(), '/info/ru/card.json')) {
                return Http::response(['vendor_code' => '100002', 'id' => 1], 200);
            }
            if (str_contains($request->url(), 'card.wb.ru/cards/v4/detail')) {
                return Http::response(['products' => [['id' => 1, 'vendorCode' => '100002']]], 200);
            }

            return Http::response(['unexpected' => true], 404);
        });

        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => $card->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Обновление фото поставлено в очередь');

        $card->refresh();
        $this->assertFalse((bool) $card->orphan_for_clone);

        Bus::assertDispatched(WbJob::class, function (WbJob $job) use ($ourNm) {
            $reflection = new \ReflectionClass($job);
            $actionProp = $reflection->getProperty('action');
            $actionProp->setAccessible(true);
            $paramsProp = $reflection->getProperty('params');
            $paramsProp->setAccessible(true);

            return $actionProp->getValue($job) === 'uploadPhotos'
                && (int) ($paramsProp->getValue($job)['nmID'] ?? 0) === $ourNm;
        });
    }

    #[TestDox('Sima-Land: basket отдаёт неверный vendor_code, card.wb.ru — верный; сирота не ставится, фото в очередь')]
    public function test_sima_land_basket_flaky_detail_confirms_queues_upload(): void
    {
        Bus::fake();

        $seller = $this->createSeller();
        $donorNm = 12_624_007;
        $ourNm = 55_555_555;
        $origSku = '200002';

        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $ourNm,
            'supplier' => 20,
            'supplierVendorCode' => "S-1-{$origSku}-1",
            'vendorCode' => $origSku,
            'supplierName' => 'Sima-Land',
            'productName' => 'Тест basket flaky',
            'chrtID' => null,
            'photo' => null,
            'sku' => (string) $donorNm,
        ]);

        SkuMapping::create([
            'origSku' => $origSku,
            'wbSku' => (string) $donorNm,
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'wbbasket.ru') && str_contains($request->url(), '/info/ru/card.json')) {
                return Http::response(['vendor_code' => 'BAD_BASKET', 'id' => 1], 200);
            }
            if (str_contains($request->url(), 'card.wb.ru/cards/v4/detail')) {
                return Http::response(['products' => [['id' => 1, 'vendorCode' => '200002']]], 200);
            }

            return Http::response(['unexpected' => true], 404);
        });

        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => $card->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Обновление фото поставлено в очередь');

        $card->refresh();
        $this->assertFalse((bool) $card->orphan_for_clone);

        Bus::assertDispatched(WbJob::class);
    }

    #[TestDox('Sima-Land: basket и detail не согласованы — ошибка без сироты')]
    public function test_sima_land_inconclusive_does_not_orphan(): void
    {
        Bus::fake();

        $seller = $this->createSeller();
        $donorNm = 12_624_007;
        $ourNm = 44_444_444;
        $origSku = '300003';

        $card = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => $ourNm,
            'supplier' => 20,
            'supplierVendorCode' => "S-1-{$origSku}-1",
            'vendorCode' => $origSku,
            'supplierName' => 'Sima-Land',
            'productName' => 'Тест inconclusive',
            'chrtID' => null,
            'photo' => null,
            'sku' => (string) $donorNm,
        ]);

        SkuMapping::create([
            'origSku' => $origSku,
            'wbSku' => (string) $donorNm,
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'wbbasket.ru') && str_contains($request->url(), '/info/ru/card.json')) {
                return Http::response(['vendor_code' => 'AAA', 'id' => 1], 200);
            }
            if (str_contains($request->url(), 'card.wb.ru/cards/v4/detail')) {
                return Http::response(['products' => [['id' => 1, 'vendorCode' => 'BBB']]], 200);
            }

            return Http::response(['unexpected' => true], 404);
        });

        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => $card->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'error');

        $card->refresh();
        $this->assertFalse((bool) $card->orphan_for_clone);

        Bus::assertNothingDispatched();
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
