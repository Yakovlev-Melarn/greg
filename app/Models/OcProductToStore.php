<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcProductToStore extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_product_to_store';
    public $timestamps = false;
}


