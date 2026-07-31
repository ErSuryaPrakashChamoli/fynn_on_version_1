<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerStageHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerJourneyService
{
    /**
     * Create Journey History
     */
    protected static function log(
        Customer $customer,
        string $stage,
        string $message
    ): void {

        CustomerStageHistory::create([
            'customer_id'  => $customer->id,
            'stage_name'   => $stage,
            'status_value' => $message,
            'user_id'      => auth()->id(),
        ]);
    }

    /**
     * Move SFL -> Underwriting
     */
    public static function moveToUnderwriting(Customer $customer): Customer
    {
        if ($customer->journey_status !== 'sfl') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in SFL stage.',
            ]);
        }

        DB::transaction(function () use ($customer) {

            $customer->update([
                'journey_status' => 'underwriting',
                'underwriting_status' => 'in_process',
            ]);

            self::log(
                $customer,
                'SFL',
                'Moved to Underwriting'
            );
        });

        return $customer->fresh();
    }

    /**
     * Approve Underwriting
     */
    public static function approve(Customer $customer): Customer
    {
        if ($customer->journey_status !== 'underwriting') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in Underwriting stage.',
            ]);
        }

        DB::transaction(function () use ($customer) {

            $customer->update([
                'journey_status' => 'approved',
                'underwriting_status' => 'approved',
            ]);

            self::log(
                $customer,
                'Underwriting',
                'Moved to Credit Approval'
            );
        });

        return $customer->fresh();
    }

    /**
     * Reject Customer
     */
    public static function reject(Customer $customer): Customer
    {
        DB::transaction(function () use ($customer) {

            $customer->update([
                'journey_status' => 'not_approved',
                'underwriting_status' => 'rejected',
            ]);

            self::log(
                $customer,
                'Underwriting',
                'Customer Rejected'
            );
        });

        return $customer->fresh();
    }

    /**
     * Credit Approved -> Sanctioned
     */
    public static function sanction(Customer $customer): Customer
    {
        if ($customer->journey_status !== 'approved') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in Approval stage.',
            ]);
        }

        DB::transaction(function () use ($customer) {

            $customer->update([
                'journey_status' => 'sanctioned',
            ]);

            self::log(
                $customer,
                'Approval',
                'Moved to Disbursal'
            );
        });

        return $customer->fresh();
    }

    /**
     * Finalize Disbursal
     */
    public static function finalize(Customer $customer): Customer
    {
        if ($customer->journey_status !== 'sanctioned') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in Disbursal stage.',
            ]);
        }

        DB::transaction(function () use ($customer) {

            $customer->update([
                'disbursal_finalized' => true,
            ]);

            self::log(
                $customer,
                'Disbursal',
                'Disbursal Finalized'
            );
        });

        return $customer->fresh();
    }

    /**
     * Submit Documents
     */
    public static function submit(Customer $customer): Customer
    {
        if (! $customer->disbursal_finalized) {
            throw ValidationException::withMessages([
                'documents' => 'Finalize Disbursal first.',
            ]);
        }

        DB::transaction(function () use ($customer) {

            $customer->update([
                'documents_submitted' => true,
            ]);

            self::log(
                $customer,
                'Customer Journey',
                'Journey Completed'
            );
        });

        return $customer->fresh();
    }

    /**
     * Check if customer can still be edited.
     */
    public static function editable(Customer $customer): bool
    {
        return ! $customer->documents_submitted;
    }

    /**
     * Check if stage is editable.
     */
    public static function stageEditable(
        Customer $customer,
        string $stage
    ): bool {

        if ($customer->documents_submitted) {
            return false;
        }

        return match ($stage) {

            'sfl' => $customer->journey_status === 'sfl',

            'underwriting' => $customer->journey_status === 'underwriting',

            'approved' => $customer->journey_status === 'approved',

            'sanctioned' => $customer->journey_status === 'sanctioned',

            default => false,
        };
    }
}
