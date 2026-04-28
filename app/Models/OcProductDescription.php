<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcProductDescription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_product_description';
    protected $fillable = [
        'name',
        'description',
        'tag',
        'meta_title',
        'meta_description',
        'meta_keyword',
        'meta_h1'
    ];
    public $timestamps = false;
}


