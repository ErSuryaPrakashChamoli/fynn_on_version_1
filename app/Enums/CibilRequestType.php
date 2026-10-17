<?php

namespace App\Enums;

/**
 * What a CIBIL request asks the bureau for. Each is a separate (billable)
 * Surepass call: the score alone, the full credit report (which carries the
 * score too), or the report as a PDF. Rows that predate the request type
 * were full-report pulls.
 */
enum CibilRequestType: string
{
    case Score = 'score';
    case FullReport = 'full_report';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Score => 'Score check',
            self::FullReport => 'Full report',
            self::Pdf => 'PDF report',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Score => 'info',
            self::FullReport => 'primary',
            self::Pdf => 'gray',
        };
    }

    /**
     * Earlier successful requests whose answer covers this one, so it can be
     * reused instead of paying for a new pull: every request type returns
     * the score; only a full report returns the report; only a PDF request
     * returns the PDF.
     *
     * @return list<self>
     */
    public function satisfiedBy(): array
    {
        return match ($this) {
            self::Score => [self::Score, self::FullReport, self::Pdf],
            self::FullReport => [self::FullReport],
            self::Pdf => [self::Pdf],
        };
    }

    /** What the customer is told the enquiry is for, recorded with their consent. */
    public function consentPurpose(CreditBureau $bureau = CreditBureau::Cibil): string
    {
        $name = $bureau->label();

        return match ($this) {
            self::Score => "{$name} credit score check to assess loan eligibility",
            self::FullReport => "{$name} credit report enquiry to assess loan eligibility",
            self::Pdf => "{$name} credit report (PDF) enquiry to assess loan eligibility",
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
