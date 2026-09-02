<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $employee_id
 * @property Carbon $follow_up_date
 * @property string $follow_up_type
 * @property string $remarks
 * @property Carbon|null $next_follow_up_date
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Employee $employee
 *
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
 *
 * @mixin \Eloquent
 */
class FollowUp extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'ai_customer_record_id',
        'lead_id',
        'employee_id',
        'follow_up_date',
        'follow_up_type',
        'remarks',
        'next_follow_up_date',
        'status',
        'email',
        'bank_id',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'next_follow_up_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function aiCustomerRecord()
    {
        return $this->belongsTo(AiCustomerRecord::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->customer_name;
        }

        if ($this->aiCustomerRecord) {
            return $this->aiCustomerRecord->value('customer_name')
                ?? ('AI Record #'.$this->aiCustomerRecord->id);
        }

        if ($this->lead) {
            return $this->lead->customer_name;
        }

        return '—';
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
