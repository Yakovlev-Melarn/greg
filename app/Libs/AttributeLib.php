<?php

namespace App\Libs;

use App\Models\OcAttribute;
use App\Models\OcAttributeDescription;

class AttributeLib
{
    public static function getAttributeIdByName($name)
    {
        if ($result = OcAttributeDescription::select('attribute_id')->where('name', $name)->first()) {
            return $result->attribute_id;
        }
        return false;
    }

    public static function createAttribute($name, $value)
    {
        $ocAttribute = new OcAttribute();
        $ocAttribute->attribute_group_id = 7;
        $ocAttribute->sort_order = 0;
        $ocAttribute->save();
        self::createAttributeDescription($ocAttribute->id, $name);
        FilterLib::createFilter($name, $value);
        return $ocAttribute->id;
    }

    public static function createAttributeDescription($ocAttributeId, $name)
    {
        $ocAttributeDescription = new OcAttributeDescription();
        $ocAttributeDescription->attribute_id = $ocAttributeId;
        $ocAttributeDescription->language_id = 1;
        $ocAttributeDescription->name = $name;
        $ocAttributeDescription->save();
    }
}
