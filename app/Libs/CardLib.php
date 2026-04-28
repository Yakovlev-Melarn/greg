<?php

namespace App\Libs;

use App\Models\OcProduct;
use App\Models\OcProductAttribute;
use App\Models\OcProductDescription;
use App\Models\OcProductDiscount;
use App\Models\OcProductFilter;
use App\Models\OcProductImage;
use App\Models\OcProductSpecial;
use App\Models\OcProductToCategory;
use App\Models\OcProductToLayout;
use App\Models\OcProductToStore;

//use Illuminate\Support\Str;

class CardLib
{
    public static function createSamsonCard(array $categories, array $data)
    {
        $date = date('Y-m-d H:i:s');
        $ocProduct = new OcProduct();
        $ocProduct->model = $data['sku'];
        $ocProduct->sku = $data['sku'];
        $ocProduct->upc = $ocProduct->jan = $ocProduct->isbn = $ocProduct->mpn = '';
        $ocProduct->location = '';
        $ocProduct->ean = $data['barcode'];
        $ocProduct->quantity = $data['amount'];
        $ocProduct->stock_status_id = 5;
        $ocProduct->image = $data['main_image'];
        $ocProduct->manufacturer_id = $data['brand_id'];
        $ocProduct->shipping = 1;
        $ocProduct->price = $data['price'];
        $ocProduct->points = $ocProduct->tax_class_id = 0;
        $ocProduct->date_available = date('Y-m-d');
        $ocProduct->weight = $data['weight'];
        $ocProduct->weight_class_id = 1;
        $ocProduct->length = $data['depth'];
        $ocProduct->width = $data['width'];
        $ocProduct->height = $data['height'];
        $ocProduct->length_class_id = 1;
        $ocProduct->status = 1;
        $ocProduct->date_added = $date;
        $ocProduct->date_modified = $date;
        $ocProduct->supplier = 2;
        $ocProduct->save();
        echo "Product created with id: {$ocProduct->product_id}\n";
        $filters = self::createProductAttribute($ocProduct->product_id, $data['options']);
        echo "Attributes created\n";
        $mainCategoryId = array_pop($categories);
        echo "Main category id: {$mainCategoryId}\n";
        self::createProductDescription($ocProduct->product_id, $data);
        echo "Description created\n";
        self::createProductFilter($ocProduct->product_id, $filters);
        echo "Filters created\n";
        self::createProductImage($ocProduct->product_id, $data['images']);
        echo "Images created\n";
        if ($data['special_price']) {
            self::createProductSpecial($ocProduct->product_id, $data['special_price']);
            echo "Special created\n";
        } elseif($data['discount']) {
            self::createProductDiscount($ocProduct->product_id, $data['discount'], $data['discount_quantity']);
            echo "Discount created\n";
        }
        CategoryLib::createCategoryFiler($mainCategoryId, $filters);
        echo "Category filters created\n";
        self::createProductToCategory($ocProduct->product_id, $mainCategoryId);
        echo "Product {$ocProduct->product_id} to main category {$mainCategoryId} created\n";
        foreach ($categories as $category) {
            self::createProductToCategory($ocProduct->product_id, $category, 0);
            echo "Product {$ocProduct->product_id} to category {$category} created\n";
        }
        self::createProductToLayout($ocProduct->product_id);
        echo "Product to layout created\n";
        self::createProductToStore($ocProduct->product_id);
        echo "Product to store created\n";
        SeoUrlLib::createUrl($ocProduct->product_id, $data['sku'], "product_id={$ocProduct->product_id}");
        echo "Url created\n";
        return $ocProduct->product_id;
    }

    public static function createCard($categoryId, $data, $oldPrice, $newPrice)
    {
        $date = date('Y-m-d H:i:s');
        $ocProduct = new OcProduct();
        $ocProduct->model = $data['nm_id'];
        $ocProduct->sku = $data['vendor_code'];
        $ocProduct->upc = '';
        $ocProduct->ean = '';
        $ocProduct->jan = '';
        $ocProduct->isbn = '';
        $ocProduct->mpn = '';
        $ocProduct->location = '';
        $ocProduct->quantity = 1;
        $ocProduct->stock_status_id = 8;
        $ocProduct->image = $data['main_image'];
        $ocProduct->manufacturer_id = $data['brand_id'];
        $ocProduct->shipping = 1;
        $ocProduct->price = $oldPrice;
        $ocProduct->points = 0;
        $ocProduct->tax_class_id = 0;
        $ocProduct->date_available = date('Y-m-d');
        $ocProduct->weight = 0.1;
        $ocProduct->weight_class_id = 1;
        $ocProduct->length = 10;
        $ocProduct->width = 10;
        $ocProduct->height = 10;
        $ocProduct->length_class_id = 1;
        $ocProduct->status = 1;
        $ocProduct->date_added = $date;
        $ocProduct->date_modified = $date;
        $ocProduct->save();
        $filters = self::createProductAttribute($ocProduct->product_id, $data['options']);
        self::createProductDescription($ocProduct->product_id, $data['description']);
        self::createProductFilter($ocProduct->product_id, $filters);
        self::createProductImage($ocProduct->product_id, $data['images']);
        self::createProductSpecial($ocProduct->product_id, $newPrice);
        if ($categoryId) {
            CategoryLib::createCategoryFiler($categoryId, $filters);
            self::createProductToCategory($ocProduct->product_id, $categoryId);
        }
        self::createProductToLayout($ocProduct->product_id);
        self::createProductToStore($ocProduct->product_id);
        SeoUrlLib::createUrl($ocProduct->product_id, $data['nm_id'], "product_id={$ocProduct->product_id}");
        return $ocProduct->product_id;
    }

    public static function createProductAttribute($productId, $options): array
    {
        $filters = [];
        foreach ($options as $option) {
            if (!$attributeId = AttributeLib::getAttributeIdByName($option['name'])) {
                $attributeId = AttributeLib::createAttribute($option['name'], $option['value']);
            }
            $filters[] = FilterLib::createFilter($option['name'], $option['value']);
            if(
                !OcProductAttribute::where('product_id', $productId)
                ->where('attribute_id', $attributeId)
                ->exists()
            ) {
                $ocProductAttribute = new OcProductAttribute();
                $ocProductAttribute->product_id = $productId;
                $ocProductAttribute->attribute_id = $attributeId;
                $ocProductAttribute->language_id = 1;
                $ocProductAttribute->text = $option['value'];
                $ocProductAttribute->save();
            }
        }
        return $filters;
    }

    public static function createProductDescription($productId, $data): void
    {
        $ocProductDescription = new OcProductDescription();
        $ocProductDescription->product_id = $productId;
        $ocProductDescription->language_id = 1;
        $ocProductDescription->name = $data['imt_name'];
        $ocProductDescription->description = $data['description'];
        $ocProductDescription->tag = "{$data['subj_name']}, {$data['subj_root_name']}";
        $ocProductDescription->meta_title = $data['imt_name'];
        $ocProductDescription->meta_description = $data['imt_name'];
        $ocProductDescription->meta_keyword = $data['imt_name'];
        $ocProductDescription->meta_h1 = $data['imt_name'];
        $ocProductDescription->save();
    }

    public static function createProductFilter($productId, $filters): void
    {
        foreach ($filters as $filterId) {
            if (!OcProductFilter::where('product_id', $productId)
                ->where('filter_id', $filterId)
                ->exists()) {
                $ocProductFilter = new OcProductFilter();
                $ocProductFilter->product_id = $productId;
                $ocProductFilter->filter_id = $filterId;
                $ocProductFilter->save();
            }
        }
    }

    public static function createProductImage($productId, $images): void
    {
        $sort = 0;
        foreach ($images as $image) {
            $ocProductImage = new OcProductImage();
            $ocProductImage->product_id = $productId;
            $ocProductImage->image = $image;
            $ocProductImage->sort_order = $sort++;
            $ocProductImage->save();
        }
    }

    public static function createProductSpecial($productId, $price): void
    {
        $ocProductSpecial = new OcProductSpecial();
        $ocProductSpecial->product_id = $productId;
        $ocProductSpecial->customer_group_id = 1;
        $ocProductSpecial->price = $price;
        $ocProductSpecial->save();
    }

    public static function createProductToCategory($productId, $categoryId, $mainCategory = 1): void
    {
        if (!OcProductToCategory::where('product_id', $productId)->where('category_id', $categoryId)->exists()) {
            $ocProductToCategory = new OcProductToCategory();
            $ocProductToCategory->product_id = $productId;
            $ocProductToCategory->category_id = $categoryId;
            $ocProductToCategory->main_category = $mainCategory;
            $ocProductToCategory->save();
        }
    }

    public static function createProductToLayout($productId): void
    {
        $ocProductToLayout = new OcProductToLayout();
        $ocProductToLayout->product_id = $productId;
        $ocProductToLayout->store_id = 0;
        $ocProductToLayout->layout_id = 0;
        $ocProductToLayout->save();
    }

    public static function createProductToStore($productId): void
    {
        $ocProductToStore = new OcProductToStore();
        $ocProductToStore->product_id = $productId;
        $ocProductToStore->store_id = 0;
        $ocProductToStore->save();
    }

    private static function createProductDiscount(mixed $id, mixed $discount, $quantity)
    {
        $ocProductDiscount = new OcProductDiscount();
        $ocProductDiscount->product_id = $id;
        $ocProductDiscount->customer_group_id = 1;
        $ocProductDiscount->quantity = $quantity;
        $ocProductDiscount->priority = 1;
        $ocProductDiscount->price = $discount;
        $ocProductDiscount->save();
    }
}
