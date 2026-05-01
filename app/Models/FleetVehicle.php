<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FleetVehicle extends Model
{
    protected $fillable = [
        'transport_company_id',
        'brand',
        'model',
        'plate_number',
        'tonnage',
        'ownership_type',
        'rent_per_day',
    ];

    public function transportCompany(): BelongsTo
    {
        return $this->belongsTo(TransportCompany::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class, 'fleet_vehicle_id');
    }
}
