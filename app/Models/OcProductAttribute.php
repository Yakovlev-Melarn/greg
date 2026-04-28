<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcProductAttribute extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_product_attribute';
    public $timestamps = false;
}


