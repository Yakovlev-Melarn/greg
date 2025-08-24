<?php

namespace App\Http\Controllers\Api;

use App\Models\Sellers;

class Seller
{
    public function list($data): array
    {
        $sellers = Sellers::all();
        if (isset($data['fields'])) {
            return self::filter($sellers, $data['fields']);
        }
        return $sellers->toArray();
    }

    private function filter($sellers, $fields): array
    {
        return $sellers->pluck(...$fields)->toArray();
    }
}
