<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string|null $category
 * @property string $emp_id
 * @property string $emp_name
 * @property string $email
 * @property int $designation
 * @property string|null $doj
 * @property string|null $reporting_date
 * @property int|null $superviser_id
 * @property int|null $manager_id
 * @property int|null $cluster_id
 * @property string|null $cost_center
 * @property string|null $unit_name
 * @property string $exit_status
 * @property string|null $exit_date
 * @property string|null $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Employee> $callers
 * @property-read int|null $callers_count
 * @property-read Employee|null $cluster
 * @property-read Employee|null $clusterManager
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> $customers
 * @property-read int|null $customers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FollowUp> $followUps
 * @property-read int|null $follow_ups_count
 * @property-read int $target_amount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lead> $leads
 * @property-read int|null $leads_count
 * @property-read Employee|null $manager
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Employee> $managers
 * @property-read int|null $managers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmployeeReportingHistory> $reportingHistories
 * @property-read int|null $reporting_histories_count
 * @property-read Employee|null $superviser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Employee> $teamLeaders
 * @property-read int|null $team_leaders_count
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\EmployeeFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereClusterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereCostCenter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereDesignation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereDoj($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereEmpId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereEmpName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereExitDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereExitStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereReportingDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereSuperviserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Employee whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Employee extends Model
{
    use HasFactory;



    public const DESIGNATION_ADMIN = 1;
    public const DESIGNATION_MANAGER = 2;
    public const DESIGNATION_TEAM_LEADER = 3;
    public const DESIGNATION_CLUSTER = 5;
    public const DESIGNATION_CALLER = 7;


    //
    protected $fillable = [
        'emp_id',
        'emp_name',
        'email',
        'designation',
        'doj',
        'reporting_date',
        'superviser_id',
        'manager_id',
        'cluster_id',
        'cost_center',
        'unit_name',
        'category',
        'position',
        'exit_status',
        'exit_date',
    ];

    protected $casts = [
        'designation' => 'integer',
    ];

    public function superviser()
    {
        return $this->belongsTo(Employee::class, 'superviser_id');
    }

    // public function teamLeaders()
    // {
    //     return $this->hasMany(Employee::class, 'manager_id')
    //      ->where('designation', 'Team Leader');
    // }

    public function teamLeaders()
    {
        return $this->hasMany(Employee::class, 'manager_id')
            ->where('designation', self::DESIGNATION_TEAM_LEADER);
    }


    // public function managers()
    // {
    //     return $this->hasMany(Employee::class, 'cluster_id')
    //         ->where('designation', 'Manager');
    // }

    public function managers()
    {
        return $this->hasMany(Employee::class, 'cluster_id')
            ->where('designation', self::DESIGNATION_MANAGER);
    }


    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function clusterManager()
    {

        return $this->belongsTo(Employee::class, 'cluster_id');
    }


    public function cluster()
    {
        return $this->belongsTo(Employee::class, 'cluster_id');
    }

    // public function callers() {
    //         return $this->hasMany(Employee::class, 'superviser_id')
    //         ->where('designation', 'Caller');
    // }


    public function callers()
    {
        return $this->hasMany(Employee::class, 'superviser_id')
            ->where('designation', self::DESIGNATION_CALLER);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function getTargetAmountAttribute(): int
    {
        $categoryTargets = [
            'platinum' => 3500000,
            'gold'     => 3000000,
            'silver'   => 2500000,
        ];

        $category = strtolower($this->category ?? 'silver');

        return $categoryTargets[$category] ?? 2500000;
    }

    public function reportingHistories()
    {
        return $this->hasMany(EmployeeReportingHistory::class);
    }


    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }


    public static function designationOptions(): array
    {
        return [
            self::DESIGNATION_ADMIN => 'Admin',
            self::DESIGNATION_MANAGER => 'Manager',
            self::DESIGNATION_TEAM_LEADER => 'Team Leader',
            self::DESIGNATION_CLUSTER => 'Cluster Manager',
            self::DESIGNATION_CALLER => 'Caller',
        ];
    }
}
