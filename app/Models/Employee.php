<?php

namespace App\Models;

use App\Services\HierarchyRoleService;
use App\Support\HierarchyHelper;
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
 * @property int|null $business_head_id
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
                'old_business_head_id' => null,
                'new_superviser_id' => $employee->superviser_id,
                'new_manager_id' => $employee->manager_id,
                'new_cluster_id' => $employee->cluster_id,
                'new_business_head_id' => $employee->business_head_id,
                'effective_date' => $employee->reporting_date
                    ?? $employee->doj
                    ?? now()->toDateString(),
                'change_type' => 'joining',
                'updated_by' => auth()->id(),
                'remarks' => 'Employee joining / initial reporting hierarchy.',
            ]);
        });

        // A login's hierarchy role follows the seat, so a promoted or
        // demoted employee never keeps the access of their old level.
        static::updated(function (Employee $employee): void {
            if ($employee->wasChanged('designation') && $employee->user) {
                app(HierarchyRoleService::class)->syncUserRole($employee->user, $employee->designation);
            }
        });
    }

    public const DESIGNATION_ADMIN = 1;

    public const DESIGNATION_MANAGER = 2;

    public const DESIGNATION_TEAM_LEADER = 3;

    public const DESIGNATION_CLUSTER = 5;

    public const DESIGNATION_CALLER = 7;

    public const DESIGNATION_BUSINESS_HEAD = 9;

    /**
     * Other Bank Support. Deliberately OUTSIDE the reporting tree: it is not
     * in DESIGNATION_RANKS (so designationRank() is 0, like Admin), reports
     * to nobody and carries no LMS target. Their targets and incentives live
     * in the Other Bank Support module, keyed on the user — see
     * OtherBankSupportService.
     */
    public const DESIGNATION_OTHER_BANK_SUPPORT = 11;

    /**
     * Seniority of each hierarchy designation, lowest first. The designation
     * codes themselves are not in seniority order (Manager = 2, Cluster = 5),
     * so always compare levels through this map. Admin sits outside the tree.
     *
     * @var array<int, int>
     */
    public const DESIGNATION_RANKS = [
        self::DESIGNATION_CALLER => 1,
        self::DESIGNATION_TEAM_LEADER => 2,
        self::DESIGNATION_MANAGER => 3,
        self::DESIGNATION_CLUSTER => 4,
        self::DESIGNATION_BUSINESS_HEAD => 5,
    ];

    /**
     * The column holding an employee's boss at each level, nearest level
     * first, keyed to the designation that column must point at. A level
     * the employee skips is left null.
     *
     * @var array<string, int>
     */
    public const REPORTING_COLUMNS = [
        'superviser_id' => self::DESIGNATION_TEAM_LEADER,
        'manager_id' => self::DESIGNATION_MANAGER,
        'cluster_id' => self::DESIGNATION_CLUSTER,
        'business_head_id' => self::DESIGNATION_BUSINESS_HEAD,
    ];

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
        'business_head_id',
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

    public function businessHead()
    {
        return $this->belongsTo(Employee::class, 'business_head_id');
    }

    /**
     * The employee this person reports to directly: the nearest filled
     * reporting column that points at that column's level, so a Team Leader
     * with no Manager reports to their Cluster Manager. See ReportingTree.
     */
    public function directBossId(): ?int
    {
        return HierarchyHelper::directBossId($this);
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
            self::DESIGNATION_BUSINESS_HEAD => 'Business Head',
            self::DESIGNATION_OTHER_BANK_SUPPORT => 'Other Bank Support',
        ];
    }

    /**
     * Seniority of a designation (see DESIGNATION_RANKS); 0 for Admin or an
     * unknown code, which sit outside the reporting tree.
     */
    public static function designationRank(?int $designation): int
    {
        return $designation === null ? 0 : (self::DESIGNATION_RANKS[$designation] ?? 0);
    }

    public static function designationColorClass(?int $designation): string
    {
        return match ($designation) {
            self::DESIGNATION_BUSINESS_HEAD => 'text-amber-600 dark:text-amber-400',
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
