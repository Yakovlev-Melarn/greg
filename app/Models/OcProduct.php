<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $nmId)
 */
class OcProduct extends Model
{
    protected $primaryKey = 'product_id';
    protected $connection = 'mysql';
    protected $table = 'oc_product';
    public $timestamps = false;
}


