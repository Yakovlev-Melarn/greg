<?php

namespace App\Jobs;

use App\Libs\CardLib;
use App\Libs\CategoryLib;
use App\Libs\FtpLib;
use App\Libs\Helper;
use App\Libs\ManufacturerLib;
use App\Models\OcProduct;
use App\Models\OcProductDiscount;
use App\Models\SamsonPrice;
use App\Models\SamsonProduct;
use App\Services\CategoryPathService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OcCreateSamsonCard implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 1;
    public int $timeout = 0;

    public function __construct()
    {
    }

    /**
     * @throws ConnectionException
     * @throws Exception
     */
    public function handle(): void
    {
        $products = SamsonProduct::select('samson_products.*')
            ->leftJoin('samson_stocks', 'samson_products.id', '=', 'samson_stocks.product_id')
            ->where('samson_products.in_shop', 0)
            ->where('samson_stocks.type', 'idp')
            ->where('samson_stocks.value', '>', 0)
            ->limit(100)
            ->inRandomOrder()
            ->get();
        if ($products->count() > 0) {
            foreach ($products as $productSelected) {
                $product = SamsonProduct::where('id', $productSelected->id)->first();
                echo "Create card for product {$product->sku}\n";
                if ($this->isEmptyProduct($product->sku)) {
                    echo "Product not found. Create new product\n";
                    $categories = $this->getCategory($product);
                    if (empty($categories)) {
                        echo "Categories not found\n";
                        echo "Skip\n";
                        continue;
                    }
                    echo "Categories: " . implode(', ', $categories) . "\n";
                    $this->createCard($categories, $product);
                    echo "Card created\n";
                } else {
                    echo "Product found\n";
                    echo "Update price\n";
                    $ocProduct = OcProduct::where('model', $product->sku)->first();
                    $prices = $this->calculatePrice($product);
                    $ocProduct->price = $prices['price'];
                    $ocProduct->save();
                    if ($prices['discountPrice']) {
                        echo "Update discount\n";
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
                $product->in_shop = 1;
                $product->save();
            }
        }
    }

    private function getCategory(SamsonProduct $product): array
    {
        $result = [];
        foreach ($product->product_categories as $category) {
            $categoryPath = CategoryPathService::getPathById($category->id);
            $parentCategory = 0;
            $foundCategory = '';
            foreach ($categoryPath as $item) {
                $ocCategory = CategoryLib::getCategoryByName($item, $parentCategory);
                if (empty($ocCategory)) {
                    echo "Category not found. Create new category name {$foundCategory}{$item}\n";
                    $url = Helper::toLatin($item);
                    CategoryLib::createCategory($item, $url, $parentCategory);
                    $ocCategory = CategoryLib::getCategoryByName($item, $parentCategory);
                    GPTJob::dispatch(
                        "category",
                        "{$item}",
                        ['categoryId' => $ocCategory->category_id]
                    )->onQueue('GPT');
                } else {
                    $foundCategory .= "{$item}/";
                }
                $parentCategory = $ocCategory->category_id;
                $result[$parentCategory] = $parentCategory;
            }
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    private function getCardData(SamsonProduct $product): array|object|bool
    {
        $images = FtpLib::uploadSamsonImages($product);
        $brandName = $product->brand ?? 'TopGiper';
        $brandId = ManufacturerLib::getManufacturerIdByName($brandName);
        if (empty($brandId)) {
            echo "Brand not found. Create new brand name {$brandName}\n";
            $brandId = ManufacturerLib::createManufacturer($brandName);
        }
        $prices = $this->calculatePrice($product);
        $result['brand_id'] = $brandId;
        $result['main_image'] = array_shift($images);
        $result['images'] = $images;
        $result['sku'] = $product->sku;
        $result['barcode'] = $product->barcode;
        $result['amount'] = $product->stocks->where('type', 'idp')->sum('value');
        $result['price'] = $prices['price'];
        $result['special_price'] = false;
        $result['discount'] = $prices['discountPrice'];
        $result['discount_quantity'] = $prices['discountQuantity'];
        $result['weight'] = $product->weight;
        $result['depth'] = $product->packageSizes->where('type', 'depth')->first()->value;
        $result['width'] = $product->packageSizes->where('type', 'width')->first()->value;
        $result['height'] = $product->packageSizes->where('type', 'height')->first()->value;
        $result['options'] = $product->facets->toArray();
        $result['subj_name'] = $result['subj_root_name'] = '';
        $result['imt_name'] = $product->name;
        $result['description'] = $product->description . ' ' . $product->description_ext;
        echo "Total card price {$result['price']}\n";
        return $result;
    }

    /**
     * @throws Exception
     */
    private function createCard(array $categories, SamsonProduct $product): bool
    {
        echo "Create card\n";
        $cardData = $this->getCardData($product);
        $cardId = CardLib::createSamsonCard($categories, $cardData);
        echo "Card id {$cardId}\n";
        if ($cardId) {
            CrawlSitemapJob::dispatch("https://novadream.ru/index.php?route=product/product&product_id={$cardId}");
            $attributeDescriptions = [];
            $characteristics = $product->characteristics;
            foreach ($characteristics as $characteristic) {
                $attributeDescriptions[] = $characteristic->name;
            }
            echo "Attribute descriptions: " . implode(', ', $attributeDescriptions) . "\n";
            echo "Create GPT job\n";
            GPTJob::dispatch(
                "product",
                "{$cardData['imt_name']}",
                ['productId' => $cardId, 'productDescription' => implode(', ', $attributeDescriptions)]
            )->onQueue('GPT');
            echo "GPT job created\n";
        }
        return $cardId;
    }

    private function isEmptyProduct($nmId): bool
    {
        return OcProduct::where('model', $nmId)->where("supplier", 2)->count() == 0;
    }

    /**
     * @throws ConnectionException
     */
    private function clearCache(): void
    {
        Http::withHeaders(["Authorization" => getenv("ND_ACCESS_KEY")])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://novadream.ru/index.php?route=api/developer/systemCache");
    }

    public function calculatePrice(SamsonProduct $product): array
    {
        $deliveryMargin = 40;
        $buildMargin = 75;
        $percent = 90;
        if (!$product->prices->where('type', 'contract')->first()) {
            echo "Price not found\n";
            $prices = SamsonPrice::where('product_id', $product->id)->where('type', 'contract')->first();
            $purchasePrice = $prices->value;
        } else {
            $purchasePrice = $product->prices->where('type', 'contract')->first()->value;
        }
        echo "Purchase price {$purchasePrice}\n";
        $recommendedPrice = $product->prices->where('type', 'infiltration')->first()->value;
        echo "Recommended price {$recommendedPrice}\n";
        $minimumOrderQuantity = $product->packages->where('type', 'min_opt')->first()->value;
        echo "Minimum order quantity {$minimumOrderQuantity}\n";
        $price = $recommendedPrice + $deliveryMargin;
        echo "Price {$price}\n";
        $discountPrice = 0;
        if ($minimumOrderQuantity > 1) {
            if ($minimumOrderQuantity > 9) {
                $discountPrice = ceil($recommendedPrice + ceil(400 / $minimumOrderQuantity));
            } else {
                $discountPrice = $price;
            }
            echo "Discount price {$discountPrice}\n";
            $price += $buildMargin;
            echo "Price {$price}\n";
        }
        return [
            'price' => ceil(($price / $percent) * 100),
            'discountPrice' => ceil(($discountPrice / $percent) * 100),
            'discountQuantity' => $minimumOrderQuantity
        ];
    }
}
