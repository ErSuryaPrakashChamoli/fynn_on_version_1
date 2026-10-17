<?php

namespace App\Enums;

/**
 * The manager's call on a lead once its CIBIL report is in. Recording it
 * moves the lead to the matching follow-up status, so the lead listing shows
 * the outcome of the bureau check.
 */
enum BureauDecision: string
{
    case Eligible = 'eligible';
    case NotEligible = 'not_eligible';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::NotEligible => 'Not Eligible',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Eligible => 'success',
            self::NotEligible => 'danger',
        };
    }

    /** The follow-up status the lead moves to (CustomerAssignment::FOLLOW_UP_STATUSES). */
    public function leadStatus(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::NotEligible => 'Not Eligible',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $decision): array => [$decision->value => $decision->label()])
            ->all();
    }
}
