<?php

namespace Tests\Feature\Api;

use App\Models\Cards;
use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CardsBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('block с card_ids: две карточки — два вызова корзины WB и bulk_results')]
    public function test_block_bulk_moves_two_cards(): void
    {
        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        $c1 = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 11_111_111,
            'supplier' => 10,
            'supplierVendorCode' => 'WB-X-111-1',
            'vendorCode' => '111',
            'supplierName' => 'WB',
            'productName' => 'A',
            'chrtID' => '1',
            'photo' => null,
            'sku' => null,
        ]);
        $c2 = Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 22_222_222,
            'supplier' => 10,
            'supplierVendorCode' => 'WB-X-222-1',
            'vendorCode' => '222',
            'supplierName' => 'WB',
            'productName' => 'B',
            'chrtID' => '2',
            'photo' => null,
            'sku' => null,
        ]);

        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/cards/block', [
            'card_ids' => [$c1->id, $c2->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('bulk_ok', 2)
            ->assertJsonPath('bulk_fail', 0);

        Http::assertSentCount(2);
    }
}
