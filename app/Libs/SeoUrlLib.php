<?php

namespace App\Libs;

use App\Models\OcSeoUrl;

class SeoUrlLib
{
    public static function createUrl($id, $url, $query): void
    {
        $seoUrl = OcSeoUrl::where('keyword', $url)->first();
        $ocSeoUrl = new OcSeoUrl();
        $ocSeoUrl->query = $query;
        $ocSeoUrl->language_id = 1;
        if (empty($seoUrl)) {
            $ocSeoUrl->keyword = $url;
        } else {
            $ocSeoUrl->keyword = $url . '-' . $id;
        }
        $ocSeoUrl->store_id = 0;
        $ocSeoUrl->save();
    }
}
