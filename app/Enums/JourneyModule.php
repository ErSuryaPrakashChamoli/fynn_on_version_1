<?php

namespace App\Enums;

use App\Models\Customer;

enum JourneyModule: string
{
    case DocumentVerification = 'document_verification';
    case Approval = 'approval';
    case BankProcessing = 'bank_processing';
    case DisbursalProcessing = 'disbursal_processing';
    case CustomerFollowUp = 'customer_follow_up';

    public function label(): string
    {
        return match ($this) {
            self::DocumentVerification => 'Document Verification',
            self::Approval => 'Approval',
            self::BankProcessing => 'Bank Processing',
            self::DisbursalProcessing => 'Disbursal Processing',
            self::CustomerFollowUp => 'Customer Follow-up',
        };
    }

    /**
     * Resolve the Manager-stage module a customer is currently sitting in,
     * based on its existing journey_status/disbursal_status/documents_submitted
     * fields. Used to decide which module a journey action belongs to without
     * introducing a new "current stage" column on customers.
     */
    public static function forCustomer(Customer $customer): self
    {
        if ($customer->disbursal_finalized || in_array($customer->disbursal_status, ['disbursed', 'carry_forward', 'on_hold'], true)) {
            return self::DisbursalProcessing;
        }

        if ($customer->journey_status === 'sanctioned') {
            return self::DisbursalProcessing;
        }

        // Internally approved and awaiting bank submission — the next
        // Manager-stage module is Bank Processing (see CustomerPanRequest),
        // not Disbursal Processing, which only begins once a bank sanctions
        // the loan.
        if ($customer->journey_status === 'approved') {
            return self::BankProcessing;
        }

        if (in_array($customer->journey_status, ['underwriting', 'not_approved'], true)) {
            return self::Approval;
        }

        if (! $customer->documents_submitted) {
            return self::DocumentVerification;
        }

        return self::Approval;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $module): array => [$module->value => $module->label()])
            ->all();
    }
}
