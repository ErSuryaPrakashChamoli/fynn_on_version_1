<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_no',
        'customer_id',
        'mis_batch_id',
        'version',

        // MIS
        'mis_disbursal_amount',
        'mis_cashback',
        'mis_subvention',
        'mis_docking',
        'mis_processing_fee',
        'mis_roi',
        'mis_lan_no',
        'mis_disbursal_date',

        // Sales snapshot
        'sales_disbursal_amount',
        'sales_cashback',
        'sales_subvention',
        'sales_docking',
        'sales_incentive',

        // Commission
        'expected_commission_percentage',
        'bank_commission_percentage',
        'expected_commission_amount',
        'bank_commission_amount',
        'company_commission',
        'variance_commission',

        // Variance
        'variance_amount',
        'variance_cashback',
        'variance_subvention',
        'variance_docking',

        // Expected tax
        'expected_tds',
        'expected_gst',
        'expected_payable_amount',

        // MIS tax
        'mis_tds',
        'mis_gst',
        'actual_payable_amount',

        // Operations
        'operations_tds',
        'operations_gst',

        // Settlement
        'settlement_tds',
        'settlement_gst',

        // Variance tax
        'variance_tds',
        'variance_gst',
        'variance_payable_amount',

        // Verification
        'status',
        'remarks',
        'verified_by',
        'verified_at',

        // Audit
        'created_by',
        'updated_by',

        // Payment
        'payment_received_date',
        'utr_number',
        'invoice_number',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',

            'mis_disbursal_amount' => 'decimal:2',
            'mis_cashback' => 'decimal:2',
            'mis_subvention' => 'decimal:2',
            'mis_docking' => 'decimal:2',
            'mis_processing_fee' => 'decimal:2',
            'mis_roi' => 'decimal:2',

            'sales_disbursal_amount' => 'decimal:2',
            'sales_cashback' => 'decimal:2',
            'sales_subvention' => 'decimal:2',
            'sales_docking' => 'decimal:2',
            'sales_incentive' => 'decimal:2',

            'expected_commission_percentage' => 'decimal:2',
            'bank_commission_percentage' => 'decimal:2',
            'expected_commission_amount' => 'decimal:2',
            'bank_commission_amount' => 'decimal:2',
            'company_commission' => 'decimal:2',
            'variance_commission' => 'decimal:2',

            'variance_amount' => 'decimal:2',
            'variance_cashback' => 'decimal:2',
            'variance_subvention' => 'decimal:2',
            'variance_docking' => 'decimal:2',

            'expected_tds' => 'decimal:2',
            'expected_gst' => 'decimal:2',
            'expected_payable_amount' => 'decimal:2',

            'mis_tds' => 'decimal:2',
            'mis_gst' => 'decimal:2',
            'actual_payable_amount' => 'decimal:2',

            'operations_tds' => 'decimal:2',
            'operations_gst' => 'decimal:2',

            'settlement_tds' => 'decimal:2',
            'settlement_gst' => 'decimal:2',

            'variance_tds' => 'decimal:2',
            'variance_gst' => 'decimal:2',
            'variance_payable_amount' => 'decimal:2',

            'verified_at' => 'datetime',
            'payment_received_date' => 'date',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function misBatch()
    {
        return $this->belongsTo(MisBatch::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function histories()
    {
        return $this->hasMany(CustomerSettlementHistory::class);
    }
}
