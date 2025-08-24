<?php

namespace App\Http\Controllers\Api;

use App\{Http\Controllers\Api\Seller as SellerAlias};
use Illuminate\Http\Request;

class Api
{
    private array $supportedEntities = [
        'sellers' => SellerAlias::class
    ];

    public function index(string $entity, string $method, Request $request): array
    {
        if (!isset($this->supportedEntities[$entity])) {
            return ['error' => true, 'message' => "$entity is not supported"];
        }
        $data = $request->post();
        $entity = new $this->supportedEntities[$entity]();
        return $entity->{$method}($data);
    }

}
