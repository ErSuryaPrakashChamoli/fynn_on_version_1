<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int|null $employee_id
 * @property string $customer_name
 * @property string $mobile_no
 * @property string|null $pan_number
 * @property string|null $current_location
 * @property string|null $job_location
 * @property numeric|null $salary
 * @property Carbon $follow_up_date
 * @property string $follow_up_type
 * @property string $status
 * @property Carbon|null $next_follow_up_date
 * @property string $remarks
 * @property bool $is_converted
 * @property int|null $converted_customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $convertedCustomer
 * @property-read Employee|null $employee
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereConvertedCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereCurrentLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereCustomerName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereFollowUpDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereFollowUpType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereIsConverted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereJobLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereMobileNo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereNextFollowUpDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead wherePanNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereSalary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Lead extends Model
{
    //

    protected $fillable = [
        'employee_id',
        'customer_name',
        'mobile_no',
        'pan_number',
        'current_location',
        'job_location',
        'salary',
        'follow_up_date',
        'follow_up_type',
        'status',
        'next_follow_up_date',
        'remarks',
        'is_converted',
        'converted_customer_id',
        'email',
        'application_no',
        'residence_location',
        'bank_id',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        // 'next_follow_up_date' => 'date',
        'next_follow_up_date' => 'datetime',
        'is_converted' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function convertedCustomer()
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    /**
     * Follow-up columns kept directly on the lead. A change to any of
     * these represents a new follow-up interaction and is mirrored into
     * the follow_ups table so it surfaces on the Lead Follow-Up Calendar
     * and the dashboard calendar.
     *
     * @var array<int, string>
     */
    protected const FOLLOW_UP_FIELDS = [
        'follow_up_type',
        'status',
        'next_follow_up_date',
        'remarks',
        'bank_id',
    ];

    protected static function booted()
    {
        static::creating(function ($lead) {
            if (Auth::check() && blank($lead->employee_id)) {
                $lead->employee_id = Auth::user()->employee?->id;
            }
        });

        static::created(function (Lead $lead) {
            $lead->logFollowUp();
        });

        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged(self::FOLLOW_UP_FIELDS)) {
                return;
            }

            $lead->logFollowUp();
        });
    }

    protected function logFollowUp(): void
    {
        $this->followUps()->create([
            'employee_id' => $this->employee_id,
            'follow_up_type' => $this->follow_up_type,
            'status' => $this->status ?? 'Pending',
            'remarks' => $this->remarks,
            'next_follow_up_date' => $this->next_follow_up_date,
            'bank_id' => $this->bank_id,
        ]);
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
