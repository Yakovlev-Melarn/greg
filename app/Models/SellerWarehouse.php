<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static create(array $attributes)
 * @method static find($id)
 * @method static findOrFail($id)
 * @method static destroy($ids)
 * @method static where(string $column, $operator = null, $value = null)
 */
class SellerWarehouse extends Model
{
    protected $table = 'seller_warehouses';

    protected $fillable = [
        'seller_id',
        'wb_warehouse_id',
        'name',
        'supplier',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'wb_warehouse_id' => 'integer',
        'supplier' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Sellers::class, 'seller_id');
    }
}
