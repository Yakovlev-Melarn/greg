<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonPackageSize extends Model
{
    protected $table = 'samson_package_sizes';
    protected $fillable = ['product_id', 'type', 'value'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
