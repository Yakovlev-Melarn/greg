<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerWarehouseStockSnapshot extends Model
{
    protected $table = 'seller_warehouse_stock_snapshots';

    protected $fillable = [
        'seller_warehouse_id',
        'chrt_id',
        'amount',
        'is_positive',
        'collected_at',
        'last_sent_to_wb_at',
    ];

    protected $casts = [
        'seller_warehouse_id' => 'integer',
        'chrt_id' => 'integer',
        'amount' => 'integer',
        'is_positive' => 'boolean',
        'collected_at' => 'datetime',
        'last_sent_to_wb_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(SellerWarehouse::class, 'seller_warehouse_id');
    }
}
