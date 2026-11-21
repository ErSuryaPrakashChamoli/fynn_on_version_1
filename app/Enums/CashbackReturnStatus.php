<?php

namespace App\Enums;

/**
 * A request to record that the customer gave a paid cashback back. The
 * case's Team Leader or Manager raises it; it counts as a returned
 * cashback (and the case's cashback drops by it) only once the Admin
 * approves it.
 */
enum CashbackReturnStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Return waiting for Admin',
            self::Approved => 'Returned',
            self::Rejected => 'Return rejected',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'danger',
            self::Rejected => 'gray',
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
