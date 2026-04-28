<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SamsonCategory extends Model
{
    protected $table = 'samson_categories';
    protected $fillable = ['id', 'name', 'parent_id', 'depth_level'];
    public $timestamps = false;

    public static function firstOrCreate(array $array, array $item)
    {
        return self::query()->firstOrCreate($array, $item);
    }

    public static function find(int $categoryId)
    {
        return self::query()->find($categoryId);
    }

    /**
     * Связь: родительская категория
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Связь: дочерние категории
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(SamsonProduct::class, 'samson_product_categories');
    }
}
