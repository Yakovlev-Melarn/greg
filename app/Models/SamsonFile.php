<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonFile extends Model
{
    protected $table = 'samson_files';
    protected $fillable = ['product_id', 'url', 'type'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
