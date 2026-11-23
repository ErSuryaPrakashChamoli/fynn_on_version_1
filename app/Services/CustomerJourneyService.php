<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerStageHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerJourneyService
{
    /**
     * The Disbursal step's outcomes (Step 4 "Disbursal Status").
     *
     * @var array<int, string>
     */
    public const DISBURSAL_STATUSES = ['disbursed', 'carry_forward', 'dropped', 'on_hold'];

    /**
     * Create Journey History
     */
    protected static function log(
        Customer $customer,
        string $stage,
        string $message
    ): void {

        CustomerStageHistory::create([
            'customer_id' => $customer->id,
            'stage_name' => $stage,
            'status_value' => $message,
            'user_id' => auth()->id(),
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
     * Refuses to move a file to Credit Approval while a required box of the
     * Underwriting or Credit Approval step is empty. Once approved those boxes
     * lock, so a gap let through here could never be filled later. Each
     * message is keyed by its form field so the form shows it under that box.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function ensureReadyForApproval(Customer $customer, array $data): void
    {
        $bank = ($data['sanctioned_bank'] ?? null) ?: $customer->bank_eligible_for;

        $missing = array_filter([
            'approval_date' => blank($data['approval_date'] ?? null) ? 'Enter the approval date.' : null,
            'underwriting_remarks' => blank($data['underwriting_remarks'] ?? null) ? 'Enter the underwriting remarks.' : null,
            'approved_loan_amount' => self::positiveAmount($data['approved_loan_amount'] ?? null) === null ? 'Enter the approved sanctioned amount.' : null,
            'sanctioned_bank' => blank($bank) ? 'Choose the final sanctioned issuing bank.' : null,
            'other_sanctioned_bank' => $bank === 'other' && blank($data['other_sanctioned_bank'] ?? null) ? 'Enter the bank name.' : null,
            'approved_remarks' => blank($data['approved_remarks'] ?? null) ? 'Enter the approved credit remarks.' : null,
        ]);

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * Refuses to close the Disbursal step while a box it needs is empty:
     * remarks always, plus date / channel / net amount / subvention for a
     * disbursal and the date for a carry forward. The cashback declaration
     * is checked separately by sanction() (CustomerCashbackService).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function ensureReadyForDisbursal(Customer $customer, array $data): void
    {
        $status = $data['disbursal_status'] ?? null;

        if (! in_array($status, self::DISBURSAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'disbursal_status' => 'Choose the disbursal status.',
            ]);
        }

        $disbursed = $status === 'disbursed';

        $missing = array_filter([
            'disbursal_date' => $disbursed && blank($data['disbursal_date'] ?? null) ? 'Enter the disbursal date.' : null,
            'channel' => $disbursed && blank($data['channel'] ?? null) ? 'Choose the channel.' : null,
            'sanctioned_loan_amount' => $disbursed && self::positiveAmount($data['sanctioned_loan_amount'] ?? null) === null ? 'Enter the final net disbursed loan amount.' : null,
            'subvention' => $disbursed
                && blank($data['subvention'] ?? null)
                && app(CustomerCashbackService::class)->subventionFromForms($customer) === null
                    ? 'Enter the subvention fees (0 if none).'
                    : null,
            'carry_forward_date' => $status === 'carry_forward' && blank($data['carry_forward_date'] ?? null) ? 'Enter the carry forward date.' : null,
            'sanctioned_remarks' => blank($data['sanctioned_remarks'] ?? null) ? 'Enter the final disbursal remarks.' : null,
        ]);

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * The digits of a typed rupee amount ("9,11,441" → "911441"), or null
     * when nothing above zero was typed.
     */
    protected static function positiveAmount(mixed $amount): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $amount);

        return $digits !== '' && (int) $digits > 0 ? $digits : null;
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

        self::ensureReadyForApproval($customer, $data);

        DB::transaction(function () use ($customer, $data) {

            $customer->update([
                'journey_status' => 'approved',
                'underwriting_status' => 'approved',

                'approved_loan_amount' => isset($data['approved_loan_amount'])
                    ? preg_replace('/[^0-9]/', '', $data['approved_loan_amount'])
                    : null,

                'sanctioned_bank' => ($data['sanctioned_bank'] ?? null) ?: $customer->bank_eligible_for,
                'other_sanctioned_bank' => $data['other_sanctioned_bank'] ?? null,
                'approved_remarks' => $data['approved_remarks'] ?? null,
                'approval_date' => $data['approval_date'] ?? null,
                'underwriting_remarks' => $data['underwriting_remarks'] ?? null,
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

        self::ensureReadyForDisbursal($customer, $data);

        // A disbursed file must declare its cashback; a declared cashback or
        // expected cashback needs its form first, and the amount always
        // comes from that form (CustomerCashbackService), never typed here.
        $disbursed = ($data['disbursal_status'] ?? null) === 'disbursed';
        $cashbackService = app(CustomerCashbackService::class);
        $cashback = $disbursed
            ? $cashbackService->cashbackForDeclaration($customer, $data['cashback_option'] ?? null)
            : $customer->cashback;

        // A subvention declared on a live cashback form is the case's; the
        // typed one applies only when no live form declares it (and then is
        // what a later rejected or retrieved form falls back to).
        $formSubvention = $cashbackService->subventionFromForms($customer);
        $typedSubvention = filled($data['subvention'] ?? null)
            ? preg_replace('/[^0-9]/', '', $data['subvention'])
            : null;

        DB::transaction(function () use ($customer, $data, $disbursed, $cashback, $cashbackService, $formSubvention, $typedSubvention) {

            if ($formSubvention === null && $cashbackService->subventionFollowsForms($customer)) {
                $customer->forceFill(['subvention_before_forms' => (float) ($typedSubvention ?? 0)]);
            }

            $journeyStatus = match ($data['disbursal_status']) {
                'disbursed' => 'sanctioned',
                'carry_forward' => 'carry_forward',
                'dropped' => 'dropped',
                default => 'approved',
            };

            $customer->update([
                'journey_status' => $journeyStatus,
                'disbursal_status' => $data['disbursal_status'] ?? null,
                'disbursal_date' => ($data['disbursal_status'] ?? null) === 'disbursed'
                    ? ($data['disbursal_date'] ?? null)
                    : null,
                'channel' => $data['channel'] ?? null,
                'sanctioned_loan_amount' => filled($data['sanctioned_loan_amount'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['sanctioned_loan_amount'])
                    : null,
                'cashback' => $cashback,
                'cashback_option' => $disbursed ? $data['cashback_option'] : $customer->cashback_option,
                'subvention' => $formSubvention ?? $typedSubvention,
                'docking' => filled($data['docking'] ?? null)
                    ? preg_replace('/[^0-9]/', '', $data['docking'])
                    : null,
                'carry_forward_date' => $data['carry_forward_date'] ?? null,
                'sanctioned_remarks' => $data['sanctioned_remarks'] ?? null,
                'disbursal_finalized' => true,
            ]);

            self::log(
                $customer->fresh(),
                'Credit Approval',
                match ($data['disbursal_status']) {
                    'disbursed' => 'Moved to Disbursal',
                    'carry_forward' => 'Marked as Carry Forward',
                    'dropped' => 'Application Dropped',
                    'on_hold' => 'Marked as On Hold',
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
