<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverAdjustmentPart extends Model
{
    protected $fillable = [
        'driver_adjustment_id',
        'part_no',
        'amount',
        'due_date',
        'is_applied',
        'applied_at',
        'comment',
    ];

    protected $casts = [
        'amount' => 'float',
        'due_date' => 'date',
        'is_applied' => 'boolean',
        'applied_at' => 'datetime',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(DriverAdjustment::class, 'driver_adjustment_id');
    }
}
