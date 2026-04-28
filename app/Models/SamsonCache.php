<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SamsonCache extends Model
{
    protected $table = 'samson_cache';
    protected $fillable = ['sku', 'cache'];
}
