<?php

namespace App\Enums;

/**
 * Where a CIBIL pull stands. Pending only lasts while the bureau call is in
 * flight; a pull that errors is kept as Failed with the reason, so the
 * attempt still shows up in the history.
 */
enum CibilReportStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Fetched',
            self::Failed => 'Failed',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Success => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
