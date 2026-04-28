<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SamsonProduct extends Model
{
    protected $table = 'samson_products';
    protected $fillable = [
        'sku', 'name', 'name_1c', 'manufacturer', 'vendor_code',
        'barcode', 'brand', 'description', 'description_ext',
        'weight', 'volume', 'nds', 'ban_not_multiple',
        'out_of_stock', 'remove_date', 'expiration_date'
    ];

    public static function updateOrCreate(array $array, array $array1)
    {
        return self::query()->updateOrCreate($array, $array1);
    }

    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return self::query()->where(...func_get_args());
    }

    public function product_categories(): BelongsToMany
    {
        return $this->belongsToMany(SamsonCategory::class, 'samson_product_categories', 'product_id', 'category_id');
    }

    public function files(): HasMany
    {
        return $this
            ->hasMany(SamsonFile::class,'product_id','id')
            ->orderBy('id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SamsonCertificate::class,'product_id','id');
    }

    public function characteristics(): HasMany
    {
        return $this->hasMany(SamsonCharacteristic::class,'product_id','id');
    }

    public function facets(): HasMany
    {
        return $this->hasMany(SamsonFacet::class,'product_id','id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(SamsonPackage::class,'product_id','id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(SamsonStock::class,'product_id','id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SamsonPrice::class,'product_id','id');
    }

    public function packageSizes(): HasMany
    {
        return $this->hasMany(SamsonPackageSize::class,'product_id','id');
    }
}
