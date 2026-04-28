<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $string2)
 */
class OcSeoUrl extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_seo_url';
    public $timestamps = false;
}


