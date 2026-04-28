<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonStock extends Model
{
    protected $table = 'samson_stocks';
    protected $fillable = ['product_id', 'type', 'value'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
