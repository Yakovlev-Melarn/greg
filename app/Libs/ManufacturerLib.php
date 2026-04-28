<?php

namespace App\Libs;

use App\Models\OcManufacturer;
use App\Models\OcManufacturerDescription;
use App\Models\OcManufacturerToLayout;
use App\Models\OcManufacturerToStore;
use Illuminate\Support\Str;

class ManufacturerLib
{
    public static function getManufacturerIdByName($name)
    {
        if ($result = OcManufacturer::select('manufacturer_id')->where('name', $name)->first()) {
            return $result->manufacturer_id;
        }
        return false;
    }

    public static function createManufacturer($name)
    {
        $ocManufacturer = new OcManufacturer();
        $ocManufacturer->name = $name;
        $ocManufacturer->image = '';
        $ocManufacturer->sort_order = 0;
        $ocManufacturer->noindex = 1;
        $ocManufacturer->save();
        $id = $ocManufacturer->id;
        self::createManufacturerDescription($id, $name);
        self::createManufacturerToLayout($id);
        self::createManufacturerToStore($id);
        self::createManufacturerUrl($id, $name);
        return $id;
    }

    public static function createManufacturerDescription($id, $name): void
    {
        $ocManufacturerDescription = new OcManufacturerDescription();
        $ocManufacturerDescription->manufacturer_id = $id;
        $ocManufacturerDescription->language_id = 1;
        $ocManufacturerDescription->description = '';
        $ocManufacturerDescription->description3 = '';
        $ocManufacturerDescription->meta_description = $name;
        $ocManufacturerDescription->meta_keyword = $name;
        $ocManufacturerDescription->meta_title = $name;
        $ocManufacturerDescription->meta_h1 = $name;
        $ocManufacturerDescription->save();
    }

    public static function createManufacturerToLayout($id): void
    {
        $ocManufacturerToLayout = new OcManufacturerToLayout();
        $ocManufacturerToLayout->manufacturer_id = $id;
        $ocManufacturerToLayout->store_id = 0;
        $ocManufacturerToLayout->layout_id = 0;
        $ocManufacturerToLayout->save();
    }

    public static function createManufacturerToStore($id): void
    {
        $ocManufacturerToStore = new OcManufacturerToStore();
        $ocManufacturerToStore->manufacturer_id = $id;
        $ocManufacturerToStore->store_id = 0;
        $ocManufacturerToStore->save();
    }

    public static function createManufacturerUrl($id, $name): void
    {
        $url = Str::slug($name);
        SeoUrlLib::createUrl($id, $url, "manufacturer_id={$id}");
    }
}
