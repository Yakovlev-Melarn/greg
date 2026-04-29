<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $data)
 * @method static where(string $string, int $int)
 */
class SkuMapping extends Model
{
    use HasFactory;

    protected $table = 'skuMapping';

    protected $fillable = [
        'origSku',
        'wbSku',
        'purchase_price',
        'logistics_cost',
        'selling_price',
        'total_cost',
        'wb_commission',
        'fulfillment_cost',
        'tax',
        'net_profit',
        'stock_quantity',
        'depth',
        'length',
        'width',
        'weight_kg',
        'wbPrice',
        'blocked',
        'user_blocked',
        'needUpdatePrice',
    ];

    protected $casts = [
        'purchase_price' => 'float',
        'logistics_cost' => 'float',
        'selling_price' => 'float',
        'total_cost' => 'float',
        'wb_commission' => 'float',
        'tax' => 'float',
        'net_profit' => 'float',
        'depth' => 'float',
        'length' => 'float',
        'width' => 'float',
        'weight_kg' => 'float',
        'blocked' => 'boolean',
        'user_blocked' => 'boolean',
        'needUpdatePrice' => 'boolean',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Cards::class, 'origSku', 'vendorCode');
    }
}
