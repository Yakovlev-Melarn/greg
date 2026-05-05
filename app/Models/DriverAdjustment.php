<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverAdjustment extends Model
{
    protected $fillable = [
        'driver_id',
        'adjustment_type',
        'event_date',
        'total_amount',
        'comment',
        'status',
        'attachments_count',
    ];

    protected $casts = [
        'event_date' => 'date',
        'total_amount' => 'float',
        'attachments_count' => 'integer',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(DriverAdjustmentPart::class)->orderBy('part_no');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DriverAdjustmentAttachment::class)->orderByDesc('id');
    }
}
