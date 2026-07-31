<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $stage_name
 * @property string $status_value
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereStageName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereStatusValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStageHistory whereUserId($value)
 * @mixin \Eloquent
 */
class CustomerStageHistory extends Model
{
    //

    // protected $fillable = [
    //     'customer_id',
    //     'stage_name',
    //     'status_value',
    //     'user_id',
    // ];

    protected $fillable = [
        'customer_id',
        'stage_id',
        'remarks',
        'stage_name',
        'status_value',
        'user_id',
        'created_by',
        'updated_by',
    ];

    // protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }



}
