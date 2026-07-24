<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    // public const DESIGNATION_ADMIN = 'Admin';
    // public const DESIGNATION_CLUSTER = 'Cluster Manager';
    // public const DESIGNATION_MANAGER = 'Manager';
    // public const DESIGNATION_TEAM_LEADER = 'Team Leader';
    // public const DESIGNATION_CALLER = 'Caller';


    // public const DESIGNATION_ADMIN = '1';
    // public const DESIGNATION_CLUSTER = '5';
    // public const DESIGNATION_MANAGER = '2';
    // public const DESIGNATION_TEAM_LEADER = '3';
    // public const DESIGNATION_CALLER = '7';
    //Employee::DESIGNATION_CALLER

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

    public function managers(){
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


    public function callers(){
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
