<?php

namespace App\Services\Ocr;

use Illuminate\Support\Str;

class OcrFieldExtractionService
{
    /**
     * Maps normalized OCR labels to actual Customer columns.
     * Only these fields can be written to customers during approval.
     */
    public const CUSTOMER_FIELD_MAP = [
        'name' => 'customer_name',
        'customer_name' => 'customer_name',
        'applicant_name' => 'customer_name',
        'full_name' => 'customer_name',
        'mobile' => 'mobile_no',
        'mobile_no' => 'mobile_no',
        'mobile_number' => 'mobile_no',
        'phone' => 'mobile_no',
        'phone_number' => 'mobile_no',
        'pan' => 'pan_number',
        'pan_number' => 'pan_number',
        'pan_no' => 'pan_number',
        'salary' => 'salary',
        'monthly_salary' => 'salary',
        'net_salary' => 'salary',
        'loan_amount' => 'eligible_loan_amount',
        'requested_loan_amount' => 'eligible_loan_amount',
        'loan_applied' => 'loan_applied',
        'sanctioned_loan_amount' => 'sanctioned_loan_amount',
        'sanction_amount' => 'sanctioned_loan_amount',
        'approved_loan_amount' => 'approved_loan_amount',
        'email' => 'email',
        'email_id' => 'email',
        'job_location' => 'job_location',
        'work_location' => 'job_location',
        'residence_location' => 'residence_location',
        'current_location' => 'current_location',
        'company_category' => 'company_category',
        'application_no' => 'application_no',
        'application_number' => 'application_no',
        'lan_no' => 'lan_no',
        'lan_number' => 'lan_no',
        'sanctioned_bank' => 'sanctioned_bank',
        'bank_name' => 'sanctioned_bank',
    ];

    public function extract(string $text, ?string $documentType = null): array
    {
        $result = [];
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }

            [$label, $value] = $this->splitLabelValue($line);
            if ($label === null || $value === null) {
                continue;
            }

            $normalized = $this->normalizeLabel($label);
            $customerField = self::CUSTOMER_FIELD_MAP[$normalized] ?? null;

            if (! $customerField || $value === '') {
                continue;
            }

            $value = $this->normalizeValue($customerField, $value);
            if ($value === null || $value === '') {
                continue;
            }

            $result[$customerField] = [
                'value' => $value,
                'confidence' => null,
                'source_label' => $label,
            ];
        }

        // PAN and mobile are important enough to detect even when OCR labels are imperfect.
        if (! isset($result['pan_number']) && preg_match('/\b([A-Z]{5}[0-9]{4}[A-Z])\b/i', $text, $match)) {
            $result['pan_number'] = [
                'value' => strtoupper($match[1]),
                'confidence' => 0.95,
                'source_label' => 'PAN pattern',
            ];
        }

        if (! isset($result['mobile_no']) && preg_match('/(?<!\d)([6-9]\d{9})(?!\d)/', preg_replace('/\D+/', ' ', $text), $match)) {
            $result['mobile_no'] = [
                'value' => $match[1],
                'confidence' => 0.90,
                'source_label' => 'Mobile pattern',
            ];
        }

        return $result;
    }

    public function allowedCustomerFields(): array
    {
        return array_values(array_unique(array_values(self::CUSTOMER_FIELD_MAP)));
    }

    private function splitLabelValue(string $line): array
    {
        if (preg_match('/^(.{2,80}?)[\s]*[:\-][\s]*(.+)$/u', $line, $match)) {
            return [$match[1], $match[2]];
        }

        if (preg_match('/^(Name|Customer Name|Mobile|Mobile No|Mobile Number|PAN|PAN No|Salary|Monthly Salary|Loan Amount|Sanctioned Loan Amount|Email|Application No|LAN No|Bank Name)\s+(.+)$/iu', $line, $match)) {
            return [$match[1], $match[2]];
        }

        return [null, null];
    }

    private function normalizeLabel(string $label): string
    {
        return Str::snake(Str::lower(trim($label)));
    }

    private function normalizeValue(string $field, string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B:;-|,");

        return match ($field) {
            'pan_number' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value)),
            'mobile_no' => preg_replace('/\D+/', '', $value),
            'salary', 'sanctioned_loan_amount', 'approved_loan_amount' => $this->numericValue($value),
            default => $value,
        };
    }

    private function numericValue(string $value): ?string
    {
        $value = preg_replace('/[^0-9.]/', '', $value);
        return $value !== '' ? $value : null;
    }
}
