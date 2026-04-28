<?php

namespace App\Jobs;

use App\Libs\CardLib;
use App\Libs\CategoryLib;
use App\Libs\FtpLib;
use App\Libs\Helper;
use App\Libs\ManufacturerLib;
use App\Libs\WBContent;
use App\Models\OcAttributeDescription;
use App\Models\OcProduct;
use App\Models\OcProductAttribute;
use App\Models\OcProductDescription;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OcCreateWbCard implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 1;
    public int $timeout = 3600;
    public mixed $nmId;
    public mixed $oldPrice;
    public mixed $newPrice;
    public mixed $detail;

    public function __construct($nmId, $oldPrice, $newPrice)
    {
        $this->nmId = $nmId;
        $this->oldPrice = $oldPrice;
        $this->newPrice = $newPrice;
    }

    /**
     * @throws ConnectionException
     * @throws Exception
     */
    public function handle(): void
    {
        if ($this->isEmptyProduct($this->nmId)) {
            $category = $this->getCategory();
            $cardData = $this->getCardData();
            $this->createCard($category, $cardData);
            $this->clearCache();
        } else {
            echo "Product {$this->nmId} already exists\n";
        }
    }

    private function getCategory(): int|null
    {
        $this->detail = (object)WBContent::getDetail($this->nmId);
        if (!$breadcrumbs = WBContent::getBreadcrumbs($this->nmId, $this->detail->subjectId)) {
            return null;
        }
        $parentCategory = 0;
        $foundCategory = '';
        foreach ($breadcrumbs as $breadcrumb) {
            $breadcrumb = (object)$breadcrumb;
            $category = CategoryLib::getCategoryByName($breadcrumb->name, $parentCategory);
            if (empty($category)) {
                echo "Category not found. Create new category name {$foundCategory}{$breadcrumb->name}\n";
                $url = Helper::seoUrl($breadcrumb->pageUrl);
                $parentCategory = CategoryLib::createCategory($breadcrumb->name, $url, $parentCategory);
                GPTJob::dispatch(
                    "category",
                    "{$foundCategory}{$breadcrumb->name}",
                    ['categoryId' => $parentCategory]
                );
                continue;
            } else {
                $foundCategory .= "{$breadcrumb->name}/";
            }
            $parentCategory = $category->category_id;
        }
        return $parentCategory;
    }

    /**
     * @throws Exception
     */
    private function getCardData(): array|object|bool
    {
        $result = WBContent::getCardInfo($this->nmId);
        if (empty($result)) {
            throw new Exception("Product {$this->nmId} not found");
        }
        $images = FtpLib::uploadWbImages($this->nmId, $result['media']['photo_count']);
        $brandName = $result['selling']['brand_name'] ?? 'TopGiper';
        $brandId = ManufacturerLib::getManufacturerIdByName($brandName);
        if (empty($brandId)) {
            echo "Brand not found. Create new brand name {$brandName}\n";
            $brandId = ManufacturerLib::createManufacturer($brandName);
        }
        $result['brand_id'] = $brandId;
        $result['main_image'] = array_shift($images);
        $result['images'] = $images;
        return $result;
    }

    private function createCard($category, $cardData): bool
    {
        $cardId = CardLib::createCard($category, $cardData, $this->oldPrice, $this->newPrice);
        if ($cardId) {
            $productDescription = OcProductDescription::where('product_id', $cardId)->first();
            $attributeDescriptions = [];
            $attributes = OcProductAttribute::where('product_id', $cardId)->get();
            foreach ($attributes as $attribute) {
                $attributeName = OcAttributeDescription::where('attribute_id', $attribute->attribute_id)->first();
                $attributeDescriptions[] = "{$attributeName->name} - {$attribute->text}";
            }
            GPTJob::dispatch(
                "product",
                "{$productDescription->name}",
                ['productId' => $cardId, 'productDescription' => implode(', ', $attributeDescriptions)]
            );
        }
        return $cardId;
    }

    private function isEmptyProduct($nmId): bool
    {
        return OcProduct::where('model', $nmId)->count() == 0;
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
}
