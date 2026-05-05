<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDailyReport extends Model
{
    protected $fillable = [
        'driver_id',
        'fleet_vehicle_id',
        'report_date',
        'work_hours',
        'extra_work_hours',
        'night_loading',
        'night_loading_amount',
        'manual_floor_lift',
        'manual_floor_lift_amount',
        'route_sheet_total',
    ];

    protected $casts = [
        'report_date' => 'date',
        'work_hours' => 'float',
        'extra_work_hours' => 'float',
        'night_loading' => 'boolean',
        'night_loading_amount' => 'float',
        'manual_floor_lift' => 'boolean',
        'manual_floor_lift_amount' => 'float',
        'route_sheet_total' => 'float',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'fleet_vehicle_id');
    }
}
