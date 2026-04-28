<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, mixed $id)
 * @method static create(array $array)
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'parent_id',
        'parent_name'
    ];

    // Связь с родительской категорией
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'category_id');
    }

    // Связь с дочерними категориями
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'category_id');
    }
}
