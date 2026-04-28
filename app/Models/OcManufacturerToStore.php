<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $name)
 */
class OcManufacturerToStore extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_manufacturer_to_store';
    public $timestamps = false;
}


