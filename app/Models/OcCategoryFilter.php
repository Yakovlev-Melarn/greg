<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $id)
 */
class OcCategoryFilter extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_category_filter';
    public $timestamps = false;
}


