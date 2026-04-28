<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static find($seller_id)
 * @method static where(string $string, mixed $get)
 */
class Sellers extends Model
{
    public function cards(): HasMany
    {
        return $this->hasMany(Cards::class, 'sellerID', 'id');
    }
}
