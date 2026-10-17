<?php

namespace App\Services\Cibil;

use App\Models\CibilReport;
use Illuminate\Support\Carbon;

/**
 * The details the bureau is asked about, as typed on the fetch form.
 */
final readonly class CibilApplicant
{
    public function __construct(
        public string $name,
        public string $panNumber,
        public ?string $mobileNo,
        public ?string $email = null,
        public ?Carbon $dateOfBirth = null,
        public ?string $gender = null,
        public ?string $pincode = null,
    ) {}

    public static function fromReport(CibilReport $report): self
    {
        return new self(
            name: $report->customer_name,
            panNumber: $report->pan_number,
            mobileNo: $report->mobile_no,
            email: $report->email,
            dateOfBirth: $report->date_of_birth,
            gender: $report->gender,
            pincode: $report->pincode,
        );
    }
}
