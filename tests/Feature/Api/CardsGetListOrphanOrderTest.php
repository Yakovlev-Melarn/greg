<?php

namespace Tests\Feature\Api;

use App\Models\Cards;
use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CardsGetListOrphanOrderTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('getlist: сироты идут после обычных карточек при одинаковой сортировке по id')]
    public function test_orphan_cards_listed_after_non_orphans(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('cards', 'orphan_for_clone')) {
            $this->markTestSkipped('cards.orphan_for_clone column missing');
        }

        $seller = new Sellers;
        $seller->name = 'Seller';
        $seller->wb_api_key = 'k';
        $seller->save();

        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 1,
            'supplier' => 10,
            'supplierVendorCode' => 'A',
            'vendorCode' => '10',
            'supplierName' => 'WB',
            'productName' => 'Orphan first id',
            'chrtID' => '1',
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => true,
        ]);
        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 2,
            'supplier' => 10,
            'supplierVendorCode' => 'B',
            'vendorCode' => '20',
            'supplierName' => 'WB',
            'productName' => 'Normal',
            'chrtID' => '2',
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => false,
        ]);
        Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 3,
            'supplier' => 10,
            'supplierVendorCode' => 'C',
            'vendorCode' => '30',
            'supplierName' => 'WB',
            'productName' => 'Orphan second id',
            'chrtID' => '3',
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => true,
        ]);

        $response = $this->postJson('/api/cards/getlist', [
            'seller' => $seller->id,
            'page' => 1,
            'per_page' => 10,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]);

        $response->assertOk();
        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(3, $items);

        $this->assertFalse((bool) ($items[0]['orphan_for_clone'] ?? false), 'first row must not be orphan');
        $this->assertSame('Normal', $items[0]['productName'] ?? '');
        $this->assertTrue((bool) ($items[1]['orphan_for_clone'] ?? false));
        $this->assertTrue((bool) ($items[2]['orphan_for_clone'] ?? false));
        $this->assertSame('Orphan first id', $items[1]['productName'] ?? '');
        $this->assertSame('Orphan second id', $items[2]['productName'] ?? '');
    }
}
