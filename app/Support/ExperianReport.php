<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads an Experian credit report (the `credit_report` object Surepass
 * returns) for the report page. The schema varies from report to report,
 * so every value is looked up defensively: a missing section gives an empty
 * list, a missing field gives null (shown as "Not available in this
 * report"), Experian's YYYYMMDD dates are formatted, and account numbers
 * are masked. Nothing is invented and nothing is dropped — sections it does
 * not know are handed back as-is for the generic viewer. It only reads; the
 * stored payload is never changed.
 */
final class ExperianReport
{
    /** Sections laid out by this reader; the rest are shown generically. */
    private const array KNOWN_SECTIONS = ['CreditProfileHeader', 'Current_Application', 'CAIS_Account', 'CAPS'];

    /**
     * @param  array<string, mixed>  $report
     */
    private function __construct(private readonly array $report) {}

    /**
     * The first report in a stored Experian answer, or null when there is
     * none to read.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        $report = $payload['credit_report'] ?? null;

        if (is_array($report) && array_is_list($report)) {
            $report = $report[0] ?? null;
        }

        return is_array($report) && $report !== [] ? new self($report) : null;
    }

    /**
     * @return array{report_date: string|null, report_time: string|null, report_number: string|null, version: string|null}
     */
    public function header(): array
    {
        $header = $this->section('CreditProfileHeader');

        return [
            'report_date' => self::date($header['ReportDate'] ?? null),
            'report_time' => self::time($header['ReportTime'] ?? null),
            'report_number' => self::text($header['ReportNumber'] ?? null),
            'version' => self::text($header['Version'] ?? null),
        ];
    }

    /**
     * The applicant as Experian holds them. PAN and mobile number are
     * masked; the full values are on the request itself.
     *
     * @return array<string, string|null>
     */
    public function applicant(): array
    {
        $details = $this->value('Current_Application.Current_Application_Details.Current_Applicant_Details');
        $address = $this->value('Current_Application.Current_Application_Details.Current_Applicant_Address_Details');
        $details = is_array($details) ? $details : [];
        $address = is_array($address) ? $address : [];

        $name = collect([$details['First_Name'] ?? null, $details['Middle_Name1'] ?? null, $details['Middle_Name2'] ?? null, $details['Middle_Name3'] ?? null, $details['Last_Name'] ?? null])
            ->map(fn (mixed $part): ?string => self::text($part))
            ->filter()
            ->implode(' ');

        $mobile = self::text($details['MobilePhoneNumber'] ?? null);
        $phone = self::text($details['Telephone_Number_Applicant_1st'] ?? null);

        $addressLine = collect([
            $address['FlatNoPlotNoHouseNo'] ?? null,
            $address['BldgNoSocietyName'] ?? null,
            $address['RoadNoNameAreaLocality'] ?? null,
            $address['Landmark'] ?? null,
            $address['City'] ?? null,
            $address['PINCode'] ?? null,
        ])->map(fn (mixed $part): ?string => self::text($part))->filter()->implode(', ');

        return [
            'Name' => $name !== '' ? $name : null,
            'PAN' => ($pan = self::text($details['IncomeTaxPan'] ?? null)) !== null ? CibilMask::pan($pan) : null,
            'Date of birth' => self::date($details['Date_Of_Birth_Applicant'] ?? null),
            'Mobile' => $mobile !== null ? CibilMask::mobile($mobile) : null,
            'Telephone' => $phone !== null ? CibilMask::mobile($phone) : null,
            'Email' => self::text($details['EMailId'] ?? null),
            'Address' => $addressLine !== '' ? $addressLine : null,
        ];
    }

    /**
     * Account counts and outstanding balances from the CAIS summary.
     *
     * @return array<string, string|null>
     */
    public function creditSummary(): array
    {
        $accounts = $this->value('CAIS_Account.CAIS_Summary.Credit_Account');
        $balances = $this->value('CAIS_Account.CAIS_Summary.Total_Outstanding_Balance');
        $accounts = is_array($accounts) ? $accounts : [];
        $balances = is_array($balances) ? $balances : [];

        return [
            'Total accounts' => self::text($accounts['CreditAccountTotal'] ?? null),
            'Active accounts' => self::text($accounts['CreditAccountActive'] ?? null),
            'Closed accounts' => self::text($accounts['CreditAccountClosed'] ?? null),
            'Defaulted accounts' => self::text($accounts['CreditAccountDefault'] ?? null),
            'Suit-filed balance' => self::amount($accounts['CADSuitFiledCurrentBalance'] ?? null),
            'Outstanding (secured)' => self::amount($balances['Outstanding_Balance_Secured'] ?? null),
            'Outstanding (unsecured)' => self::amount($balances['Outstanding_Balance_UnSecured'] ?? null),
            'Outstanding (total)' => self::amount($balances['Outstanding_Balance_All'] ?? null),
        ];
    }

    /**
     * One row per credit account (CAIS_Account_DETAILS), account number
     * masked to its last four characters.
     *
     * @return list<array<string, string|null>>
     */
    public function accounts(): array
    {
        return array_map(fn (array $account): array => [
            'Lender' => self::text($account['Subscriber_Name'] ?? null),
            'Account type' => self::text($account['Account_Type'] ?? null),
            'Account number' => self::maskAccount($account['Account_Number'] ?? null),
            'Opened' => self::date($account['Open_Date'] ?? null),
            'Closed' => self::date($account['Date_Closed'] ?? null),
            'Sanctioned / limit' => self::amount($account['Highest_Credit_or_Original_Loan_Amount'] ?? $account['Credit_Limit_Amount'] ?? null),
            'Current balance' => self::amount($account['Current_Balance'] ?? null),
            'Amount overdue' => self::amount($account['Amount_Past_Due'] ?? null),
            'Status' => self::text($account['Account_Status'] ?? null),
            'Payment history' => self::text($account['Payment_History_Profile'] ?? null),
            'Reported' => self::date($account['Date_Reported'] ?? null),
        ], $this->rows('CAIS_Account.CAIS_Account_DETAILS'));
    }

    /**
     * Credit enquiries (Experian's CAPS application details).
     *
     * @return list<array<string, string|null>>
     */
    public function enquiries(): array
    {
        return array_map(fn (array $enquiry): array => [
            'Institution' => self::text($enquiry['Subscriber_Name'] ?? null),
            'Date' => self::date($enquiry['Date_of_Request'] ?? null),
            'Reason' => self::text($enquiry['Enquiry_Reason'] ?? null),
            'Purpose' => self::text($enquiry['Finance_Purpose'] ?? null),
            'Amount' => self::amount($enquiry['Amount_Financed'] ?? null),
        ], $this->rows('CAPS.CAPS_Application_Details'));
    }

    /**
     * Every section this reader does not lay out, untouched.
     *
     * @return array<string, mixed>
     */
    public function otherSections(): array
    {
        return Arr::except($this->report, self::KNOWN_SECTIONS);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->report[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    private function value(string $path): mixed
    {
        return Arr::get($this->report, $path);
    }

    /**
     * A section that may be one object or a list of them, as a list of
     * objects (anything else is skipped).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $path): array
    {
        $rows = $this->value($path);

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $rows = array_is_list($rows) ? $rows : [$rows];

        return array_values(array_filter($rows, fn (mixed $row): bool => is_array($row) && ! array_is_list($row)));
    }

    private static function text(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Experian dates are numbers like 20240705; anything else is shown as given. */
    private static function date(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{8}$/', $value) === 1) {
            try {
                $date = Carbon::createFromFormat('!Ymd', $value);

                if ($date instanceof Carbon && $date->format('Ymd') === $value) {
                    return $date->format('d M Y');
                }
            } catch (Throwable) {
                // Not a real date; shown as given below.
            }
        }

        return $value;
    }

    /** Experian times are numbers like 172347 (HHMMSS). */
    private static function time(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value !== null && preg_match('/^(\d{2})(\d{2})(\d{2})$/', str_pad($value, 6, '0', STR_PAD_LEFT), $parts) === 1) {
            return "{$parts[1]}:{$parts[2]}:{$parts[3]}";
        }

        return $value;
    }

    private static function amount(mixed $value): ?string
    {
        $value = self::text($value);

        return $value !== null && is_numeric($value) ? '₹'.indianNumberFormat($value) : $value;
    }

    /** Only the last four characters are shown (most reports already mask the rest). */
    private static function maskAccount(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        return strlen($value) <= 4 ? $value : str_repeat('X', strlen($value) - 4).substr($value, -4);
    }
}
