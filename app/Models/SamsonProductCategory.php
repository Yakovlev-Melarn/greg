<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamsonProductCategory extends Model
{
    protected $table = 'samson_product_categories';
    protected $fillable = ['product_id', 'category_id'];
    public $timestamps = false;
}
