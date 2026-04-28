<?php

namespace App\Libs;

use App\Models\OcCategory;
use App\Models\OcCategoryDescription;
use App\Models\OcCategoryFilter;
use App\Models\OcCategoryPath;
use App\Models\OcCategoryToLayout;
use App\Models\OcCategoryToStore;

class CategoryLib
{
    public static function getCategoryByName($name, $parentId = 0)
    {
        return OcCategoryDescription::leftJoin('oc_category', function ($join) {
            $join->on('oc_category.category_id', '=', 'oc_category_description.category_id');
        })
            ->where("oc_category_description.name", $name)
            ->where('oc_category.parent_id', $parentId)
            ->first();
    }

    public static function createCategory($name, $url, $parentId)
    {
        $date = date('Y-m-d H:i:s');
        $ocCategory = new OcCategory();
        $ocCategory->parent_id = $parentId;
        $ocCategory->column = 1;
        $ocCategory->status = 1;
        $ocCategory->top = 0;
        $ocCategory->date_added = $date;
        $ocCategory->date_modified = $date;
        if (!$parentId) {
            $ocCategory->top = 1;
        }
        $ocCategory->save();
        self::createCategoryDescription($ocCategory->id, $name);
        self::createCategoryUrl($ocCategory->id, $url);
        self::createCategoryStore($ocCategory->id);
        self::createCategoryLayout($ocCategory->id);
        self::createCategoryPath($ocCategory->id, $parentId);
        return $ocCategory->id;
    }

    public static function createCategoryDescription($id, $name): void
    {

        $ocCategoryDescription = new OcCategoryDescription();
        $ocCategoryDescription->category_id = $id;
        $ocCategoryDescription->language_id = 1;
        $ocCategoryDescription->name = $name;
        $ocCategoryDescription->description = '';
        $ocCategoryDescription->meta_title = $name;
        $ocCategoryDescription->meta_description = $name;
        $ocCategoryDescription->meta_keyword = $name;
        $ocCategoryDescription->meta_h1 = $name;
        $ocCategoryDescription->save();
    }

    public static function createCategoryUrl($id, $url): void
    {
        SeoUrlLib::createUrl($id, $url, "category_id={$id}");
    }

    public static function createCategoryStore($id): void
    {
        $ocCategoryToStore = new OcCategoryToStore();
        $ocCategoryToStore->category_id = $id;
        $ocCategoryToStore->store_id = 0;
        $ocCategoryToStore->save();
    }

    public static function createCategoryLayout($id): void
    {
        $ocCategoryToLayout = new OcCategoryToLayout();
        $ocCategoryToLayout->category_id = $id;
        $ocCategoryToLayout->store_id = 0;
        $ocCategoryToLayout->layout_id = 0;
        $ocCategoryToLayout->save();
    }

    public static function createCategoryPath($id, $parentId): void
    {
        $pathLevel = 0;
        $ocpResult = OcCategoryPath::where('category_id', $parentId)
            ->orderBy('level', 'ASC')
            ->get();
        if (count($ocpResult) > 0) {
            foreach ($ocpResult as $item) {
                self::addCategoryPath($id, $item->path_id, $pathLevel);
                $pathLevel++;
            }
        }
        self::addCategoryPath($id, $id, $pathLevel);
    }

    public static function addCategoryPath($id, $pathId, $pathLevel): void
    {
        $ocCategoryPath = new OcCategoryPath();
        $ocCategoryPath->category_id = $id;
        $ocCategoryPath->path_id = $pathId;
        $ocCategoryPath->level = $pathLevel;
        $ocCategoryPath->save();
    }

    public static function createCategoryFiler($id, $filters): void
    {
        foreach ($filters as $filterId) {
            if (!OcCategoryFilter::where('category_id', $id)
                ->where('filter_id', $filterId)
                ->first()) {
                $ocCategoryFilter = new OcCategoryFilter();
                $ocCategoryFilter->category_id = $id;
                $ocCategoryFilter->filter_id = $filterId;
                $ocCategoryFilter->save();
            }
        }
    }
}
