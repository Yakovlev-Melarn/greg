<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DriverAdjustmentAttachment extends Model
{
    protected $fillable = [
        'driver_adjustment_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(DriverAdjustment::class, 'driver_adjustment_id');
    }

    public function publicUrl(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }
}
