<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonPrice extends Model
{
    protected $table = 'samson_prices';
    protected $fillable = ['product_id', 'type', 'value'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
