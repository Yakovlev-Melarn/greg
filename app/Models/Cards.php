<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static count()
 * @method static orderBy(string $string, string $string1)
 * @method static where(string $string, mixed $seller)
 */
class Cards extends Model
{
    protected $fillable = [
        'created_at',
        'updated_at',
        'nmID',
        'sellerID',
        'supplier',
        'supplierVendorCode',
        'vendorCode',
        'supplierName',
        'productName',
        'chrtID',
        'photo',
        'sku',
        'orphan_for_clone',
        'wb_created_at',
    ];

    protected $casts = [
        'orphan_for_clone' => 'boolean',
        'wb_created_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Sellers::class, 'sellerID', 'id');
    }
}
