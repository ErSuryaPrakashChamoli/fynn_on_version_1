<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $country
 * @property string $state
 * @property string $city
 * @property string|null $state_code
 * @property string|null $city_code
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCityCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereStateCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class City extends Model
{
    //

     protected $fillable = [
        'country',
        'state',
        'city',
        'state_code',
        'city_code',
        'is_active',
    ];
}
