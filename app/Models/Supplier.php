<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(mixed $validated)
 * @method static find(mixed $supplierId)
 * @method static orderBy(string $string, string $string1)
 */
class Supplier extends Model
{
    protected $fillable = [
        'name',
        'link'
    ];
}
