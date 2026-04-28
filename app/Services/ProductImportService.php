<?php

namespace App\Services;

use App\Jobs\OcCreateSamsonCard;
use App\Models\OcProduct;
use App\Models\OcProductDiscount;
use App\Models\SamsonCache;
use App\Models\SamsonCertificate;
use App\Models\SamsonCharacteristic;
use App\Models\SamsonFacet;
use App\Models\SamsonFile;
use App\Models\SamsonPackage;
use App\Models\SamsonPackageSize;
use App\Models\SamsonPrice;
use App\Models\SamsonProduct;
use App\Models\SamsonStock;

class ProductImportService
{
    public function importFromApi(array $apiData): void
    {
        foreach ($apiData['data'] as $item) {
            $updateProduct = false;
            $createProduct = false;
            $hash = md5(json_encode($item));
            if (!SamsonCache::where('cache', $hash)->first()) {
                echo "HIT {$hash}\n";
                SamsonCache::updateOrCreate(['sku' => $item['sku']], ['cache' => $hash]);
                $createProduct = true;
            }
            $savedCache = SamsonCache::where('sku', $item['sku'])->first();
            if ($hash !== $savedCache->cache) {
                echo "HIT {$hash}\n";
                $savedCache->update(['cache' => $hash]);
                $updateProduct = true;
            } elseif (!$createProduct) {
                echo "MISS {$hash}\n";
            }
            if (!$product = SamsonProduct::where('sku', $item['sku'])->first()) {
                $product = SamsonProduct::updateOrCreate(
                    ['sku' => $item['sku']],
                    [
                        'name' => $item['name'],
                        'name_1c' => $item['name_1c'] ?? null,
                        'manufacturer' => $item['manufacturer'] ?? null,
                        'vendor_code' => $item['vendor_code'] ?? null,
                        'barcode' => $item['barcode'] ?? null,
                        'brand' => $item['brand'] ?? null,
                        'description' => $item['description'] ?? null,
                        'description_ext' => $item['description_ext'] ?? null,
                        'weight' => $item['weight'] ?? null,
                        'volume' => $item['volume'] ?? null,
                        'nds' => $item['nds'] ?? null,
                        'ban_not_multiple' => $item['ban_not_multiple'] ?? 0,
                        'out_of_stock' => $item['out_of_stock'] ?? 0,
                        'remove_date' => $item['remove_date'] ?? null,
                        'expiration_date' => $item['expiration_date'] ?? null,
                    ]
                );
                // 2. Категории
                if (!empty($item['category_list'])) {
                    $product->product_categories()->sync($item['category_list']);
                }
                // 3. Файлы (фото, сертификаты)
                $this->importFiles($product, $item);
                // 4. Расширенные сертификаты
                $this->importCertificates($product, $item);
                // 5. Характеристики
                $this->importCharacteristics($product, $item);
                // 6. Фасеты (фильтры)
                $this->importFacets($product, $item);
                // 7. Упаковки и кратности
                $this->importPackages($product, $item);
                // 8. Остатки на складах
                $this->importStocks($product, $item);
                // 9. Цены
                $this->importPrices($product, $item);
                // 10. Размеры упаковки
                $this->importPackageSizes($product, $item);
            } else {
                if ($updateProduct) {
                    $this->importStocks($product, $item);
                    $this->importPrices($product, $item);
                    if ($product->in_shop) {
                        if ($ocProduct = OcProduct::where('model', $product->sku)->first()) {
                            $amount = $product->stocks->where('type', 'idp')->sum('value');
                            $ocProduct->update(['quantity' => $amount, 'status' => $amount ? 1 : 0]);
                            $ocCreateSamsonProduct = new OcCreateSamsonCard();
                            $prices = $ocCreateSamsonProduct->calculatePrice($product);
                            $ocProduct->price = $prices['price'];
                            $ocProduct->save();
                            if ($prices['discountPrice']) {
                                if (!$ocDiscount = OcProductDiscount::where('product_id', $ocProduct->product_id)->first()) {
                                    $ocDiscount = new OcProductDiscount();
                                }
                                $ocDiscount->product_id = $ocProduct->product_id;
                                $ocDiscount->customer_group_id = 1;
                                $ocDiscount->quantity = $prices['discountQuantity'];
                                $ocDiscount->price = $prices['discountPrice'];
                                $ocDiscount->save();
                            }
                        }
                    }
                }
            }
        }
    }

    private function importFiles(SamsonProduct $product, array $item): void
    {
        // Фото
        if (!empty($item['photo_list'])) {
            foreach ($item['photo_list'] as $url) {
                SamsonFile::create([
                    'product_id' => $product->id,
                    'url' => $url,
                    'type' => 'photo'
                ]);
            }
        }

        // Сертификаты (базовые ссылки)
        if (!empty($item['certificate_list'])) {
            foreach ($item['certificate_list'] as $url) {
                SamsonFile::create([
                    'product_id' => $product->id,
                    'url' => $url,
                    'type' => 'certificate'
                ]);
            }
        }

        // Документы (из file_list)
        if (!empty($item['file_list'])) {
            foreach ($item['file_list'] as $url) {
                SamsonFile::create([
                    'product_id' => $product->id,
                    'url' => $url,
                    'type' => 'document'
                ]);
            }
        }
    }

    private function importCertificates(SamsonProduct $product, array $item): void
    {
        if (!empty($item['certificate_extended_list'])) {
            foreach ($item['certificate_extended_list'] as $cert) {
                SamsonCertificate::create([
                    'product_id' => $product->id,
                    'issued_by' => $cert['issued_by'] ?? null,
                    'active_to' => $cert['active_to'] ?? null,
                    'name' => $cert['name'] ?? null
                ]);
            }
        }
    }

    private function importCharacteristics(SamsonProduct $product, array $item): void
    {
        if (!empty($item['characteristic_list'])) {
            foreach ($item['characteristic_list'] as $char) {
                SamsonCharacteristic::create([
                    'product_id' => $product->id,
                    'name' => $char,
                    'value' => null // значение не указано в исходном массиве
                ]);
            }
        }
    }

    private function importFacets(SamsonProduct $product, array $item): void
    {
        if (!empty($item['facet_list'])) {
            foreach ($item['facet_list'] as $facet) {
                SamsonFacet::create([
                    'product_id' => $product->id,
                    'name' => $facet['name'],
                    'value' => $facet['value']
                ]);
            }
        }
    }

    private function importPackages(SamsonProduct $product, array $item): void
    {
        if (!empty($item['package_list'])) {
            foreach ($item['package_list'] as $package) {
                SamsonPackage::create([
                    'product_id' => $product->id,
                    'type' => $package['type'],
                    'value' => $package['value']
                ]);
            }
        }
    }

    private function importStocks(SamsonProduct $product, array $item): void
    {
        if (!empty($item['stock_list'])) {
            foreach ($item['stock_list'] as $stock) {
                SamsonStock::updateOrCreate([
                    'product_id' => $product->id], [
                    'type' => $stock['type'],
                    'value' => (int)$stock['value']
                ]);
            }
        }
    }

    private function importPrices(SamsonProduct $product, array $item): void
    {
        if (!empty($item['price_list'])) {
            foreach ($item['price_list'] as $price) {
                SamsonPrice::updateOrCreate([
                    'product_id' => $product->id], [
                    'type' => $price['type'],
                    'value' => (float)$price['value']
                ]);
            }
        }
    }

    private function importPackageSizes(SamsonProduct $product, array $item): void
    {
        if (!empty($item['package_size'])) {
            foreach ($item['package_size'] as $size) {
                SamsonPackageSize::create([
                    'product_id' => $product->id,
                    'type' => $size['type'],
                    'value' => (float)$size['value']
                ]);
            }
        }
    }
}
