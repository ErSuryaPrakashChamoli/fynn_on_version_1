<?php

namespace App\Enums;

/**
 * Whether an approved cashback has reached the customer. Set by the Admin
 * or Accounts; On Hold needs a reason.
 */
enum CashbackPaymentStatus: string
{
    case NotPaid = 'not_paid';
    case Paid = 'paid';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::NotPaid => 'Not Paid',
            self::Paid => 'Paid',
            self::OnHold => 'On Hold',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::NotPaid => 'gray',
            self::Paid => 'success',
            self::OnHold => 'warning',
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
