<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_id
 * @property numeric $target_amount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeTarget whereTargetAmount($value)
 * @mixin \Eloquent
 */
class EmployeeTarget extends Model
{
    //
}
