<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Employee;

/**
 * @property-read Employee|null $employee
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadGeneration newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadGeneration newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadGeneration query()
 * @mixin \Eloquent
 */
class LeadGeneration extends Model
{
    use HasFactory;

    protected $table = 'lead_generations';
    //

   protected $fillable = [
        'employee_id', // Still keeps track of which agent generated/owns this lead
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
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'next_follow_up_date' => 'date',
    ];


    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    
}
