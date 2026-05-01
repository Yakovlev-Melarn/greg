<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'name',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class, 'expense_category_id');
    }
}
