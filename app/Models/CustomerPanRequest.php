<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPanRequest extends Model
{
    protected $fillable = [
        'request_no',


        'customer_id',

        'requested_by',
        'requested_by_emp_id',
        'requested_by_name',

        'team_leader_id',
        'team_leader_name',

        'manager_id',
        'manager_name',

        'cluster_manager_id',
        'cluster_manager_name',

        'requested_bank_id',
        'requested_bank_name',

        'requested_loan_type',

        'reason',

        'status',

        'approved_by',
        'approved_at',
        'remarks',

        'application_id',

        'pan_number',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (CustomerPanRequest $request) {
            if (! $request->request_no) {
                $request->updateQuietly([
                    'request_no' => 'PR' . str_pad($request->id, 6, '0', STR_PAD_LEFT),
                ]);
            }
        });
    }



    /**
     * Existing Customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Employee who raised the request
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    /**
     * Requested Bank
     */
    public function requestedBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'requested_bank_id');
    }

    /**
     * Team Leader
     */
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_id');
    }

    /**
     * Manager
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Cluster Manager
     */
    public function clusterManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cluster_manager_id');
    }

    /**
     * Admin who approved/rejected
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    // Uncomment when LoanApplication model is ready.
    /*
    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'application_id');
    }
    */

    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';
}
