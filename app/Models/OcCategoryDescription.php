<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static leftJoin(string $string, \Closure $param)
 */
class OcCategoryDescription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_category_description';
    protected $fillable = [
        'name',
        'description',
        'meta_title',
        'meta_description',
        'meta_keyword',
        'meta_h1'
    ];
    public $timestamps = false;
}


