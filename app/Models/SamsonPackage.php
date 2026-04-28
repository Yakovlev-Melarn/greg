<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonPackage extends Model
{
    protected $table = 'samson_packages';
    protected $fillable = ['product_id', 'type', 'value'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
