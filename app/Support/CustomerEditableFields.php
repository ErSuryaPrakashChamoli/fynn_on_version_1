<?php

namespace App\Support;

use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Models\City;
use App\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * The customer-journey fields a user may ask the Admin to change through a
 * CustomerEditRequest, grouped by the journey form's sections and labelled
 * as the form labels them. Each entry knows its input type and, for a
 * dropdown, its choices — the same choices CustomerForm offers.
 *
 * Deliberately left out, because each has its own controlled workflow:
 *  - eligibility_status: only through CustomerEligibilityService (see the
 *    eligibility rule in .ai/rules/app.md);
 *  - journey_status: the Application Stage is computed by the journey
 *    service as the steps are completed;
 *  - assign_to: ownership moves through reassignment / delegation.
 */
final class CustomerEditableFields
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_SELECT = 'select';

    /**
     * @return array<string, array{label: string, fields: array<string, array{label: string, type: string}>}>
     */
    public static function sections(): array
    {
        return [
            'basic_details' => [
                'label' => 'Customer Basic Details',
                'fields' => [
                    'pan_number' => ['label' => 'PAN Number', 'type' => self::TYPE_TEXT],
                    'customer_name' => ['label' => 'Customer Name', 'type' => self::TYPE_TEXT],
                    'mobile_no' => ['label' => 'Mobile Number', 'type' => self::TYPE_TEXT],
                    'email' => ['label' => 'Email Address', 'type' => self::TYPE_TEXT],
                    'job_location' => ['label' => 'Job Location', 'type' => self::TYPE_SELECT],
                    'residence_location' => ['label' => 'Residence Location', 'type' => self::TYPE_SELECT],
                    'salary' => ['label' => 'Salary', 'type' => self::TYPE_NUMBER],
                    'current_location' => ['label' => 'Current Location', 'type' => self::TYPE_SELECT],
                    'eligibility_reason' => ['label' => 'Not Eligible Reason', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
            'journey_configuration' => [
                'label' => 'Journey Configuration',
                'fields' => [
                    'company_category' => ['label' => 'Company Name', 'type' => self::TYPE_TEXT],
                    'loan_applied' => ['label' => 'Loan Type', 'type' => self::TYPE_SELECT],
                    'other_loan_applied' => ['label' => 'Other Loan Type', 'type' => self::TYPE_TEXT],
                    'bank_eligible_for' => ['label' => 'Bank Eligible For', 'type' => self::TYPE_SELECT],
                    'other_bank_eligible_for' => ['label' => 'Other Bank Name', 'type' => self::TYPE_TEXT],
                ],
            ],
            'step_1_sfl' => [
                'label' => 'Step 1: SFL (Source File Logging)',
                'fields' => [
                    'application_no' => ['label' => 'Application No', 'type' => self::TYPE_TEXT],
                    'lan_no' => ['label' => 'Loan Account Number', 'type' => self::TYPE_TEXT],
                    'eligible_loan_amount' => ['label' => 'Eligible Loan Amount', 'type' => self::TYPE_NUMBER],
                    'documentation_status' => ['label' => 'Documentation Status', 'type' => self::TYPE_SELECT],
                    'sfl_remarks' => ['label' => 'SFL Remarks', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
            'step_2_underwriting' => [
                'label' => 'Step 2: Underwriting Analysis',
                'fields' => [
                    'underwriting_status' => ['label' => 'Underwriting Status Decision', 'type' => self::TYPE_SELECT],
                    'approval_date' => ['label' => 'Approval Date', 'type' => self::TYPE_DATE],
                    'underwriting_remarks' => ['label' => 'Underwriting Remarks', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
            'step_3_credit_approval' => [
                'label' => 'Step 3: Credit Approval Information',
                'fields' => [
                    'approved_loan_amount' => ['label' => 'Approved Sanctioned Amount', 'type' => self::TYPE_NUMBER],
                    'sanctioned_bank' => ['label' => 'Final Sanctioned Issuing Bank', 'type' => self::TYPE_SELECT],
                    'other_sanctioned_bank' => ['label' => 'Other Sanctioned Bank Name', 'type' => self::TYPE_TEXT],
                    'approved_remarks' => ['label' => 'Approved Credit Remarks', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
            'step_4_disbursal' => [
                'label' => 'Step 4: Disbursal Payouts & Close',
                'fields' => [
                    'disbursal_status' => ['label' => 'Disbursal Status', 'type' => self::TYPE_SELECT],
                    'disbursal_date' => ['label' => 'Disbursal Date', 'type' => self::TYPE_DATE],
                    'channel' => ['label' => 'Channel Name', 'type' => self::TYPE_SELECT],
                    'sanctioned_loan_amount' => ['label' => 'Final Net Disbursed Loan Amount', 'type' => self::TYPE_NUMBER],
                    'cashback' => ['label' => 'Cashback Given', 'type' => self::TYPE_NUMBER],
                    'subvention' => ['label' => 'Subvention Fees', 'type' => self::TYPE_NUMBER],
                    'docking' => ['label' => 'Docking Charges', 'type' => self::TYPE_NUMBER],
                    'carry_forward_date' => ['label' => 'Carry Forward Date', 'type' => self::TYPE_DATE],
                    'sanctioned_remarks' => ['label' => 'Final Disbursal Remarks', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
            'rejection' => [
                'label' => 'Pipeline Exception / Rejection System',
                'fields' => [
                    'journey_not_approved_reason' => ['label' => 'Not Approved Stage Reason', 'type' => self::TYPE_SELECT],
                    'not_approved_remarks' => ['label' => 'Detailed Terminal Rejection Remarks', 'type' => self::TYPE_TEXTAREA],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string> section key => label
     */
    public static function sectionOptions(): array
    {
        return array_map(fn (array $section): string => $section['label'], self::sections());
    }

    /**
     * @return array<string, string> field => label
     */
    public static function fieldOptions(?string $section): array
    {
        return array_map(
            fn (array $field): string => $field['label'],
            self::sections()[$section]['fields'] ?? [],
        );
    }

    /**
     * @return array{label: string, type: string}|null
     */
    public static function field(?string $section, ?string $field): ?array
    {
        return self::sections()[$section]['fields'][$field] ?? null;
    }

    public static function isEditable(?string $section, ?string $field): bool
    {
        return self::field($section, $field) !== null;
    }

    public static function sectionLabel(?string $section): string
    {
        return self::sections()[$section]['label'] ?? (string) $section;
    }

    public static function fieldLabel(?string $section, ?string $field): string
    {
        return self::field($section, $field)['label'] ?? (string) $field;
    }

    public static function type(?string $section, ?string $field): string
    {
        return self::field($section, $field)['type'] ?? self::TYPE_TEXT;
    }

    /**
     * The dropdown choices for a select field — the same lists CustomerForm uses.
     *
     * @return array<string, string>
     */
    public static function choices(string $field): array
    {
        $cities = fn (bool $withState): array => City::query()
            ->where('is_active', 1)
            ->orderBy('city')
            ->get()
            ->mapWithKeys(fn (City $city): array => [$city->city => $withState ? "{$city->city}, {$city->state}" : $city->city])
            ->all();

        return match ($field) {
            'job_location' => $cities(false),
            'residence_location', 'current_location' => $cities(true),
            'loan_applied' => [
                'personal_loan' => 'Personal Loan',
                'business_loan' => 'Business Loan',
                'home_loan' => 'Home Loan',
                'car_loan' => 'Car Loan',
                'education_loan' => 'Education Loan',
                'gold_loan' => 'Gold Loan',
                'lap' => 'Loan Against Property',
                'credit_card' => 'Credit Card',
                'overdraft' => 'Overdraft',
                'other' => 'Other',
            ],
            'bank_eligible_for' => CustomerForm::bankOptions(),
            'sanctioned_bank' => [...CustomerForm::bankOptions(), 'other' => 'Other'],
            'documentation_status' => ['pending' => 'Pending', 'complete' => 'Complete'],
            'underwriting_status' => ['in_process' => 'In Process', 'approved' => 'Approved', 'rejected' => 'Rejected'],
            'disbursal_status' => ['disbursed' => 'Disbursed', 'carry_forward' => 'Carry Forward', 'dropped' => 'Dropped', 'on_hold' => 'On Hold'],
            'channel' => [
                'finance_buddha' => 'Finance Buddha',
                'profin_care' => 'Profin Care',
                'rare_crome' => 'Rare Crome',
                'ruloans' => 'Ruloans',
                'fast_credit' => 'Fast Credit',
                'kms_finbud' => 'KMS Finbud',
            ],
            'journey_not_approved_reason' => [
                'cibil_score' => 'CIBIL Score Issue',
                'defaulter_bounces' => 'Defaulter / Technical Bounces',
                'no_residence_proof' => 'No Residence Proof Found',
                'low_salary' => 'Low Salary Cap',
                'location_issue' => 'Location Blacklisted',
            ],
            default => [],
        };
    }

    /**
     * The field's current value on the customer, as a plain string (dates
     * as Y-m-d, decimals without trailing zeros), or null when empty.
     */
    public static function currentValue(Customer $customer, string $field): ?string
    {
        $value = $customer->getAttribute($field);

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        $value = (string) $value;

        if (is_numeric($value) && str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    /**
     * A stored value as a person reads it: the dropdown's label for a
     * select, "12 Sep 2026" for a date, "₹1,50,000" for an amount.
     */
    public static function display(string $section, string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match (self::type($section, $field)) {
            self::TYPE_SELECT => self::choices($field)[$value] ?? $value,
            self::TYPE_DATE => rescue(fn (): string => Carbon::parse($value)->format('d M Y'), $value, false),
            self::TYPE_NUMBER => is_numeric($value) ? '₹'.self::indianNumber((float) $value) : $value,
            default => $value,
        };
    }

    /**
     * display() plus, for an amount, the figure in words — "₹12,50,000
     * (Twelve Lakh Fifty Thousand)" — so a stray zero is easy to spot.
     */
    public static function displayWithWords(string $section, string $field, ?string $value): string
    {
        $shown = self::display($section, $field, $value);

        if (self::type($section, $field) !== self::TYPE_NUMBER || $value === null || ! is_numeric($value)) {
            return $shown;
        }

        return $shown.' ('.indianAmountInWords($value).')';
    }

    /**
     * Turns a requested string into what the column stores: a float for an
     * amount, Y-m-d for a date, null for blank.
     */
    public static function cast(string $section, string $field, ?string $value): mixed
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        return match (self::type($section, $field)) {
            self::TYPE_NUMBER => is_numeric($value) ? (float) $value : null,
            self::TYPE_DATE => Carbon::parse($value)->toDateString(),
            default => $value,
        };
    }

    private static function indianNumber(float $amount): string
    {
        $formatter = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format($amount);
    }
}
