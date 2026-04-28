<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $name)
 */
class OcManufacturerDescription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_manufacturer_description';
    public $timestamps = false;
}


