<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcProductToCategory extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_product_to_category';
    public $timestamps = false;
}


