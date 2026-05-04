<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerWarehouseStockHistory extends Model
{
    protected $table = 'seller_warehouse_stock_histories';

    protected $fillable = [
        'seller_warehouse_id',
        'chrt_id',
        'amount',
        'is_positive',
        'wb_eligible',
        'included_in_wb_batch',
        'wb_sent_at',
        'collected_at',
        'run_key',
    ];

    protected $casts = [
        'seller_warehouse_id' => 'integer',
        'chrt_id' => 'integer',
        'amount' => 'integer',
        'is_positive' => 'boolean',
        'wb_eligible' => 'boolean',
        'included_in_wb_batch' => 'boolean',
        'wb_sent_at' => 'datetime',
        'collected_at' => 'datetime',
        'run_key' => 'string',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(SellerWarehouse::class, 'seller_warehouse_id');
    }
}
