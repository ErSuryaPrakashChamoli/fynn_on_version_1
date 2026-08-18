<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_id
 * @property int|null $old_superviser_id
 * @property int|null $old_manager_id
 * @property int|null $old_cluster_id
 * @property int|null $new_superviser_id
 * @property int|null $new_manager_id
 * @property int|null $new_cluster_id
 * @property \Illuminate\Support\Carbon $effective_date
 * @property string|null $effective_to
 * @property string $change_type
 * @property int|null $updated_by
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereChangeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereEffectiveDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereEffectiveTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereNewClusterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereNewManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereNewSuperviserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereOldClusterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereOldManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereOldSuperviserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployeeReportingHistory whereUpdatedBy($value)
 * @mixin \Eloquent
 */
class EmployeeReportingHistory extends Model
{
    protected $table = 'employee_reporting_history';

    protected $fillable = [
        'employee_id',
        'old_superviser_id',
        'old_manager_id',
        'old_cluster_id',
        'new_superviser_id',
        'new_manager_id',
        'new_cluster_id',
        'effective_date',
        'change_type',
        'updated_by',
        'remarks',
        'effective_to'
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];
}
