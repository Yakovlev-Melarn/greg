<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static leftJoin(string $string, \Closure $param)
 * @method static where(string $string, $name)
 */
class OcFilterGroupDescription extends Model
{
    protected $connection = 'mysql';
    protected $table = 'oc_filter_group_description';
    public $timestamps = false;
    public function filters()
    {
        return $this->hasMany(OcFilterDescription::class, 'filter_group_id', 'filter_group_id');
    }
}
