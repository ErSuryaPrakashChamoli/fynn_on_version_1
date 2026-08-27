<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Employee> $callers
 * @property-read int|null $callers_count
 * @property-read Employee|null $cluster
 * @property-read Employee|null $clusterManager
 * @property-read Collection<int, Customer> $customers
 * @property-read int|null $customers_count
 * @property-read Collection<int, FollowUp> $followUps
 * @property-read int|null $follow_ups_count
 * @property-read int $target_amount
 * @property-read Collection<int, Lead> $leads
 * @property-read int|null $leads_count
 * @property-read Employee|null $manager
 * @property-read Collection<int, Employee> $managers
 * @property-read int|null $managers_count
 * @property-read Collection<int, EmployeeReportingHistory> $reportingHistories
 * @property-read int|null $reporting_histories_count
 * @property-read Employee|null $superviser
 * @property-read Collection<int, Employee> $teamLeaders
 * @property-read int|null $team_leaders_count
 * @property-read User|null $user
 *
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
 *
 * @mixin \Eloquent
 */
class Employee extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Employee $employee): void {
            if ($employee->reportingHistories()->exists()) {
                return;
            }

            EmployeeReportingHistory::query()->create([
                'employee_id' => $employee->id,
                'old_superviser_id' => null,
                'old_manager_id' => null,
                'old_cluster_id' => null,
                'new_superviser_id' => $employee->superviser_id,
                'new_manager_id' => $employee->manager_id,
                'new_cluster_id' => $employee->cluster_id,
                'effective_date' => $employee->reporting_date
                    ?? $employee->doj
                    ?? now()->toDateString(),
                'change_type' => 'joining',
                'updated_by' => auth()->id(),
                'remarks' => 'Employee joining / initial reporting hierarchy.',
            ]);
        });
    }

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

    public function getInitialsAttribute(): string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $this->emp_name)))
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('');

        return $initials !== '' ? mb_strtoupper($initials) : '?';
    }

    public function getTargetAmountAttribute(): int
    {
        return is_numeric($this->category) ? (int) $this->category : 2500000;
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

    public static function designationColorClass(?int $designation): string
    {
        return match ($designation) {
            self::DESIGNATION_CLUSTER => 'text-violet-600 dark:text-violet-400',
            self::DESIGNATION_MANAGER => 'text-blue-600 dark:text-blue-400',
            self::DESIGNATION_TEAM_LEADER => 'text-teal-600 dark:text-teal-400',
            self::DESIGNATION_CALLER => 'text-slate-500 dark:text-slate-400',
            default => 'text-gray-500 dark:text-gray-400',
        };
    }

    public function loginSessions()
    {
        return $this->hasMany(UserLoginSession::class);
    }

    public function assignmentBatchesCreated()
    {
        return $this->hasMany(CustomerAssignmentBatch::class, 'assigned_by');
    }

    public function assignmentsReceived()
    {
        return $this->hasMany(CustomerAssignment::class, 'employee_id');
    }

    public function delegationsGiven()
    {
        return $this->hasMany(CustomerJourneyDelegation::class, 'delegating_manager_id');
    }

    public function delegationsReceived()
    {
        return $this->hasMany(CustomerJourneyDelegation::class, 'acting_manager_id');
    }

    public function journeyTakeovers()
    {
        return $this->hasMany(JourneyTakeover::class, 'takeover_by_id');
    }

    /**
     * Employees who were active at any point during [$start, $end] — joined
     * on or before the period ends, and (if exited) not exited before the
     * period starts. Used by the global month selector to answer "who was
     * active in that month" for the Employees/Teams lists, rather than the
     * meaningless "created that month".
     */
    public function scopeActiveDuring(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query
            ->where(function (Builder $query) use ($end) {
                $query->whereNull('doj')->orWhere('doj', '<=', $end);
            })
            ->where(function (Builder $query) use ($start) {
                $query->where('exit_status', '!=', 'yes')
                    ->orWhereNull('exit_date')
                    ->orWhere('exit_date', '>=', $start);
            });
    }
}
