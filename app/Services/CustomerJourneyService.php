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

            // self::log(
            //     $customer,
            //     'SFL',
            //     'Moved to Underwriting'
            // );
        });

        return $customer->fresh();
    }

    /**
     * Approve Underwriting
     */
    public static function approve(Customer $customer, array $data): Customer
    {



        if ($customer->journey_status !== 'underwriting') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in Underwriting stage.',
            ]);
        }

        DB::transaction(function () use ($customer, $data) {


            $customer->update([
                'journey_status'        => 'approved',
                'underwriting_status'   => 'approved',

                'approved_loan_amount'  => isset($data['approved_loan_amount'])
                    ? preg_replace('/[^0-9]/', '', $data['approved_loan_amount'])
                    : null,

                'sanctioned_bank'       => $data['sanctioned_bank'] ?? null,
                'other_sanctioned_bank' => $data['other_sanctioned_bank'] ?? null,
                'approved_remarks'      => $data['approved_remarks'] ?? null,
                'approval_date'         => $data['approval_date'] ?? null,
                'underwriting_remarks'         => $data['underwriting_remarks'] ?? null,
            ]);

            // $customer->refresh();
            // dd($customer->toArray());

            self::log(
                $customer->fresh(),
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




    public static function sanction(Customer $customer, array $data): Customer
    {
        if ($customer->journey_status !== 'approved') {
            throw ValidationException::withMessages([
                'journey_status' => 'Customer is not in Approved stage.',
            ]);
        }

        DB::transaction(function () use ($customer, $data) {

            $journeyStatus = match ($data['disbursal_status']) {
                'disbursed'      => 'sanctioned',
                'carry_forward'  => 'carry_forward',
                'dropped'        => 'dropped',
                default          => 'approved',
            };

            $customer->update([
                'journey_status'         => $journeyStatus,
                'disbursal_status'       => $data['disbursal_status'] ?? null,
                'channel'                => $data['channel'] ?? null,
                'sanctioned_loan_amount' => filled($data['sanctioned_loan_amount'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['sanctioned_loan_amount'])
                    : null,
                'cashback'               => filled($data['cashback'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['cashback'])
                    : null,
                'subvention'             => filled($data['subvention'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['subvention'])
                    : null,
                'docking'                => filled($data['docking'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['docking'])
                    : null,
                'carry_forward_date'     => $data['carry_forward_date'] ?? null,
                'sanctioned_remarks'     => $data['sanctioned_remarks'] ?? null,
                'disbursal_finalized' => true,
            ]);

            self::log(
                $customer->fresh(),
                'Credit Approval',
                match ($data['disbursal_status']) {
                    'disbursed' => 'Moved to Disbursal',
                    'carry_forward' => 'Marked as Carry Forward',
                    'dropped' => 'Application Dropped',
                }
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
