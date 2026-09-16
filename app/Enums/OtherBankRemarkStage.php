<?php

namespace App\Enums;

/**
 * The customer-journey step an Other Bank Support remark is written against.
 * Mirrors the four steps of CustomerForm's "Application Progress Steps".
 */
enum OtherBankRemarkStage: string
{
    case Sfl = 'sfl';
    case Underwriting = 'underwriting';
    case CreditApproval = 'credit_approval';
    case Disbursal = 'disbursal';

    public function label(): string
    {
        return match ($this) {
            self::Sfl => 'Step 1: SFL (Source File Logging)',
            self::Underwriting => 'Step 2: Underwriting',
            self::CreditApproval => 'Step 3: Credit Approval',
            self::Disbursal => 'Step 4: Disbursal',
        };
    }

    /**
     * The step a customer file is currently sitting at, used as the default
     * when a support user adds a remark.
     */
    public static function forJourneyStatus(?string $journeyStatus): self
    {
        return match ($journeyStatus) {
            'underwriting', 'not_approved' => self::Underwriting,
            'approved' => self::CreditApproval,
            'sanctioned', 'carry_forward', 'dropped' => self::Disbursal,
            default => self::Sfl,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $stage): array => [$stage->value => $stage->label()])
            ->all();
    }
}
