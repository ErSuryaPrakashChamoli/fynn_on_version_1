<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

// use Spatie\Activitylog\Traits\LogsActivity;
// use Spatie\Activitylog\LogOptions;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Activity;
use App\Models\ActivityLog;
use App\Models\CustomerStageHistory;

class Customer extends Model
{
    use HasFactory, LogsActivity;
    //
    protected $fillable = [
        'customer_name',
        'mobile_no',
        'email',
        'pan_number',
        'job_location',
        'residence_location',
        'salary',
        'current_location',
        'company_category',
        'bank_eligible_for',
        'loan_applied',
        'eligibility_status',
        'eligibility_reason',
        'journey_status',
        'journey_not_approved_reason',
        'sanctioned_bank',
        'sanctioned_loan_amount',
        'cashback',
        'subvention',
        'payout_rate',
        'bank_condition',
        'attachment_required',
        'attachment_file',
        'other_bank_eligible_for',
        'application_no',
        'lan_no',
        'documentation_status',
        'pending_document',
        'sfl_remarks',
        'underwriting_remarks',
        'approved_remarks',
        'sanctioned_remarks',
        'not_approved_remarks',
        'assign_to',
        'employee_id',
        'eligible_loan_amount',
        'docking',
        'underwriting_status',
        'approved_loan_amount',
        'disbursal_status',
        'carry_forward_date',
        'channel',
        'disbursal_pdf',
        'other_loan_applied',
        'documents_submitted',
        'disbursal_finalized',
        'approval_date',
        'other_sanctioned_bank'
    ];


    protected $casts = [
        'pending_document' => 'array',
        'disbursal_pdf' => 'array',
        'documents_submitted' => 'boolean',
    ];


    protected static function booted(): void
    {
        static::creating(function ($customer) {

            if (blank($customer->application_no)) {

                $date = now()->format('ymd'); // 260630

                $last = self::whereDate('created_at', today())
                    ->where('application_no', 'like', "FA{$date}%")
                    ->latest('id')
                    ->first();

                $sequence = 1;

                if ($last) {
                    $sequence = (int) substr($last->application_no, -6) + 1;
                }

                $customer->application_no = sprintf(
                    'FA%s%06d',
                    $date,
                    $sequence
                );
            }
        });

        static::updated(function (Customer $customer) {

            if (! $customer->wasChanged('journey_status')) {
                return;
            }

            CustomerStageHistory::create([
                'customer_id'  => $customer->id,
                'stage_name'   => ucfirst(str_replace('_', ' ', $customer->getOriginal('journey_status'))) . ' Stage',
                'status_value' => 'Moved to ' . ucfirst(str_replace('_', ' ', $customer->journey_status)),
                'user_id'      => auth()->id(),
            ]);
        });
    }

    public function assignedTo()
    {
        return $this->belongsTo(Employee::class, 'assign_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();

        // return LogOptions::defaults()
        //     ->logAll()
        //     ->logOnlyDirty()
        //     ->dontLogEmptyChanges()
        //     ->logExcept([
        //         'created_at',
        //         'updated_at',
        //     ]);
    }

    public function activities()
    {
        return $this->morphMany(
            ActivityLog::class,
            'subject'
        );
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }


    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
