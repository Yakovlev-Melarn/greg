<?php

namespace App\Libs;

use App\Models\OcFilter;
use App\Models\OcFilterDescription;
use App\Models\OcFilterGroup;
use App\Models\OcFilterGroupDescription;

class FilterLib
{
    public static function getFilterByNameValue($name, $value)
    {
        if (!$group = OcFilterGroupDescription::where('name', $name)->first()) {
            return false;
        };
        return OcFilterDescription::where('filter_group_id', $group->filter_group_id)
            ->where('name', $value)->first();
    }

    public static function createFilter($name, $value)
    {
        if (!$filter = self::getFilterByNameValue($name, $value)) {
            if (!$filterGroupDescription = OcFilterGroupDescription::where('name', $name)
                ->first()) {
                $filterGroupId = self::createFilterGroup();
                self::createFilterGroupDescription($filterGroupId, $name);
            } else {
                $filterGroupId = $filterGroupDescription->filter_group_id;
            }
            if (!$filterDescription = OcFilterDescription::where('name', $value)
                ->where('filter_group_id', $filterGroupId)
                ->first()) {
                $ocFilter = new OcFilter();
                $ocFilter->filter_group_id = $filterGroupId;
                $ocFilter->sort_order = 0;
                $ocFilter->save();
                self::createFilterDescription($ocFilter->id, $filterGroupId, $value);
                return $ocFilter->id;
            } else {
                return $filterDescription->filter_id;
            }
        }
        return $filter->filter_id;
    }

    public static function createFilterGroup()
    {
        $ocFilterGroup = new OcFilterGroup();
        $ocFilterGroup->sort_order = 0;
        $ocFilterGroup->save();
        return $ocFilterGroup->id;
    }

    public static function createFilterGroupDescription($filterGroupId, $name)
    {
        $ocFilterGroupDescription = new OcFilterGroupDescription();
        $ocFilterGroupDescription->filter_group_id = $filterGroupId;
        $ocFilterGroupDescription->language_id = 1;
        $ocFilterGroupDescription->name = $name;
        $ocFilterGroupDescription->save();
    }

    public static function createFilterDescription($filterId, $filterGroupId, $value)
    {
        $ocFilterDescription = new OcFilterDescription();
        $ocFilterDescription->filter_id = $filterId;
        $ocFilterDescription->language_id = 1;
        $ocFilterDescription->filter_group_id = $filterGroupId;
        $ocFilterDescription->name = $value;
        $ocFilterDescription->save();
    }
}
