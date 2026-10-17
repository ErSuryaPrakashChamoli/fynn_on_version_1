<?php

namespace App\Enums;

/**
 * Where a CIBIL request stands. Pending only lasts while the bureau call is
 * in flight; a request that errors is kept as Failed with the reason, so the
 * attempt still shows up in the history. Uncertain means the provider may
 * have processed (and billed) the request but no usable answer came back —
 * a timeout, a gateway error or an unreadable response — so it is never
 * retried automatically and blocks a new request for the same PAN until
 * someone verifies it.
 */
enum CibilReportStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Fetched',
            self::Failed => 'Failed',
            self::Uncertain => 'Outcome uncertain',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Uncertain => 'warning',
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
