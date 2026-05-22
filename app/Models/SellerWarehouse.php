<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public const SIMA_STOCK_VIA_SIMA_API = 'sima_api';

    public const SIMA_STOCK_VIA_WB_CATALOG = 'wb_catalog';

    protected $fillable = [
        'seller_id',
        'wb_warehouse_id',
        'name',
        'supplier',
        'stock_supplier_ids',
        'sima_stock_via',
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
        'stock_supplier_ids' => 'array',
        'stock_collect_enabled' => 'boolean',
        'stock_send_to_wb' => 'boolean',
        'stock_frequency_minutes' => 'integer',
        'stock_last_run_at' => 'datetime',
        'stock_last_run_result' => 'array',
        'sima_stock_via' => 'string',
    ];

    /**
     * Поставщики карточек для сбора остатков на этом складе (из JSON или legacy supplier).
     *
     * @return list<int>
     */
    public function effectiveStockSupplierIds(): array
    {
        $raw = $this->stock_supplier_ids;
        if (is_array($raw) && $raw !== []) {
            $out = [];
            foreach ($raw as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $out[$n] = true;
                }
            }

            return array_values(array_map('intval', array_keys($out)));
        }

        if ($this->supplier === null || (int) $this->supplier === 0) {
            return [10];
        }

        return [(int) $this->supplier];
    }

    /**
     * Синхронизация legacy supplier для UI/API: 20 если в маршруте есть Sima, иначе null.
     *
     * @param  list<int>  $ids
     */
    public static function legacySupplierFromStockSupplierIds(array $ids): ?int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (in_array(20, $ids, true)) {
            return 20;
        }

        return null;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Sellers::class, 'seller_id');
    }

    public function stockSnapshots(): HasMany
    {
        return $this->hasMany(SellerWarehouseStockSnapshot::class, 'seller_warehouse_id');
    }

    public function stockHistories(): HasMany
    {
        return $this->hasMany(SellerWarehouseStockHistory::class, 'seller_warehouse_id');
    }
}
