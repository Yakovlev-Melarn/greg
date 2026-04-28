<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static leftJoin(string $string, \Closure $param)
 */
class OcFilterDescription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_filter_description';
    public $timestamps = false;
}
