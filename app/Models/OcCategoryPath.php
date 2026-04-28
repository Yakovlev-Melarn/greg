<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $string2)
 */
class OcCategoryPath extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_category_path';
    public $timestamps = false;
}


