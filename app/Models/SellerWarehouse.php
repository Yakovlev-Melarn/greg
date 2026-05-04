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
        'stock_collect_enabled',
        'stock_send_to_wb',
        'stock_frequency_minutes',
        'stock_last_run_at',
        'stock_last_run_result',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'wb_warehouse_id' => 'integer',
        'supplier' => 'integer',
        'stock_collect_enabled' => 'boolean',
        'stock_send_to_wb' => 'boolean',
        'stock_frequency_minutes' => 'integer',
        'stock_last_run_at' => 'datetime',
        'stock_last_run_result' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Sellers::class, 'seller_id');
    }
}
