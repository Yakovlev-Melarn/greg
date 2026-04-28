<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $name)
 * @method static select(string $string)
 */
class OcManufacturer extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_manufacturer';
    public $timestamps = false;
}


