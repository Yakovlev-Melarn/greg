<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Logistician extends Model
{
    protected $fillable = [
        'full_name',
        'telegram',
        'payout_start_date',
        'payout_percent',
        'is_active',
    ];

    protected $casts = [
        'payout_start_date' => 'date',
        'payout_percent' => 'float',
        'is_active' => 'boolean',
    ];

    public function payouts(): HasMany
    {
        return $this->hasMany(LogisticianPayout::class);
    }
}
