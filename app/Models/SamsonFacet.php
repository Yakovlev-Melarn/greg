<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonFacet extends Model
{
    protected $table = 'samson_facets';
    protected $fillable = ['product_id', 'name', 'value'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
