<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Bank;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $employee_id
 * @property \Illuminate\Support\Carbon $follow_up_date
 * @property string $follow_up_type
 * @property string $remarks
 * @property \Illuminate\Support\Carbon|null $next_follow_up_date
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Customer $customer
 * @property-read \App\Models\Employee $employee
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereFollowUpDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereFollowUpType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereNextFollowUpDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class FollowUp extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'employee_id',
        'follow_up_date',
        'follow_up_type',
        'remarks',
        'next_follow_up_date',
        'status',
        'email',
        'bank_id'
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'next_follow_up_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // public function bank()
    // {
    //     return $this->belongsTo(Bank::class);
    // }

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
