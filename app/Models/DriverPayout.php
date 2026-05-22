<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverPayout extends Model
{
    protected $fillable = [
        'driver_id',
        'week_monday',
        'amount',
        'accrual_amount',
        'bonus_amount',
        'penalty_amount',
        'paid_at',
        'comment',
    ];

    protected $casts = [
        'week_monday' => 'date',
        'amount' => 'float',
        'accrual_amount' => 'float',
        'bonus_amount' => 'float',
        'penalty_amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
