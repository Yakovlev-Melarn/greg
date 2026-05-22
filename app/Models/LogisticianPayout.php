<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticianPayout extends Model
{
    protected $fillable = [
        'logistician_id',
        'week_monday',
        'route_sheets_base_amount',
        'percent',
        'amount',
        'paid_at',
    ];

    protected $casts = [
        'week_monday' => 'date',
        'route_sheets_base_amount' => 'float',
        'percent' => 'float',
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function logistician(): BelongsTo
    {
        return $this->belongsTo(Logistician::class);
    }
}
