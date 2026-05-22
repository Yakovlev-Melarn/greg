<?php

namespace Tests\Feature\Api;

use App\Models\Sellers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CloneProductsOrphanScanTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('startOrphanScan без seller_id возвращает 422')]
    public function test_requires_seller(): void
    {
        $response = $this->postJson('/api/clone-products/startOrphanScan', [
            'quantity' => 100,
            'batch_size' => 20,
        ]);

        $response->assertStatus(422);
    }

    #[TestDox('startOrphanScan с seller_id возвращает job_id')]
    public function test_dispatches_with_seller(): void
    {
        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        $response = $this->postJson('/api/clone-products/startOrphanScan', [
            'quantity' => 500,
            'batch_size' => 20,
            'seller_id' => $seller->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['job_id', 'message']);
        $this->assertStringStartsWith('clone_', $response->json('job_id'));
    }

    #[TestDox('startOrphanCatalogScan без seller_id возвращает 422')]
    public function test_catalog_requires_seller(): void
    {
        $response = $this->postJson('/api/clone-products/startOrphanCatalogScan', [
            'supplier_id' => 1,
            'quantity' => 1000,
        ]);

        $response->assertStatus(422);
    }

    #[TestDox('startOrphanCatalogScan с seller и supplier возвращает job_id')]
    public function test_catalog_dispatches(): void
    {
        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        $response = $this->postJson('/api/clone-products/startOrphanCatalogScan', [
            'supplier_id' => 999999,
            'quantity' => 1000,
            'seller_id' => $seller->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['job_id', 'message']);
    }

    #[TestDox('startOrphanCatalogScan принимает флаг переобхода только unchecked')]
    public function test_catalog_accepts_retry_unchecked_flag(): void
    {
        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        $response = $this->postJson('/api/clone-products/startOrphanCatalogScan', [
            'supplier_id' => 999999,
            'quantity' => 1000,
            'seller_id' => $seller->id,
            'orphan_catalog_retry_unchecked_only' => true,
        ]);

        $response->assertOk();
    }

    #[TestDox('orphanSupplierVendorCodes без seller возвращает 422')]
    public function test_orphan_codes_requires_seller(): void
    {
        $response = $this->postJson('/api/clone-products/orphanSupplierVendorCodes', []);

        $response->assertStatus(422);
    }

    #[TestDox('orphanSupplierVendorCodes возвращает уникальные supplierVendorCode сирот')]
    public function test_orphan_supplier_vendor_codes_list(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('cards', 'orphan_for_clone')) {
            $this->markTestSkipped('cards.orphan_for_clone missing');
        }

        $seller = new Sellers;
        $seller->name = 'Seller Test';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        \App\Models\Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 10_010_001,
            'supplier' => 20,
            'supplierVendorCode' => 'LC-S-111-1',
            'vendorCode' => '111',
            'supplierName' => 'Sima-Land',
            'productName' => 'A',
            'chrtID' => null,
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => true,
        ]);
        \App\Models\Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 10_010_002,
            'supplier' => 20,
            'supplierVendorCode' => 'LC-S-222-1',
            'vendorCode' => '222',
            'supplierName' => 'Sima-Land',
            'productName' => 'B',
            'chrtID' => null,
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => true,
        ]);
        \App\Models\Cards::create([
            'sellerID' => $seller->id,
            'nmID' => 10_010_003,
            'supplier' => 20,
            'supplierVendorCode' => 'LC-S-333-1',
            'vendorCode' => '333',
            'supplierName' => 'Sima-Land',
            'productName' => 'C',
            'chrtID' => null,
            'photo' => null,
            'sku' => null,
            'orphan_for_clone' => false,
        ]);

        $response = $this->postJson('/api/clone-products/orphanSupplierVendorCodes', [
            'seller_id' => $seller->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('count', 2);
        $codes = $response->json('codes');
        $this->assertIsArray($codes);
        sort($codes);
        $this->assertSame(['LC-S-111-1', 'LC-S-222-1'], $codes);
    }
}
