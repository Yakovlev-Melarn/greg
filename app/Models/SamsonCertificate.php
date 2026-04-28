<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonCertificate extends Model
{
    protected $table = 'samson_certificates';
    protected $fillable = ['product_id', 'issued_by', 'active_to', 'name'];

    public static function create(array $array): void
    {
        self::query()->create($array);
    }
}
