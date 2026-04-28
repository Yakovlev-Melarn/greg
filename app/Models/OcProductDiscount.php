<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcProductDiscount extends Model
{
    protected $primaryKey = 'product_discount_id';
    protected $connection = 'mysql';
    protected $table = 'oc_product_discount';
    public $timestamps = false;
}


