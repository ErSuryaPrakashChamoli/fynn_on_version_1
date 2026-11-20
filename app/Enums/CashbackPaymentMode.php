<?php

namespace App\Enums;

/**
 * How a cashback is paid out, as on the paper voucher. UPI asks for the
 * app and the customer's UPI ID / number; NEFT and IMPS for an account
 * number and IFSC; cash for nothing more.
 */
enum CashbackPaymentMode: string
{
    case Upi = 'upi';
    case Neft = 'neft';
    case Imps = 'imps';
    case Cash = 'cash';

    /** @var array<string, string> */
    public const UPI_PLATFORMS = [
        'gpay' => 'Google Pay',
        'phonepe' => 'PhonePe',
        'paytm' => 'Paytm',
        'other' => 'Other UPI app',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Upi => 'UPI',
            self::Neft => 'NEFT',
            self::Imps => 'IMPS',
            self::Cash => 'Cash',
        };
    }

    public function needsBankAccount(): bool
    {
        return in_array($this, [self::Neft, self::Imps], true);
    }

    /** Every mode but cash leaves a UTR / transaction reference. */
    public function needsTransactionId(): bool
    {
        return $this !== self::Cash;
    }

    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? '—';
    }

    public static function upiPlatformLabel(?string $value): string
    {
        return self::UPI_PLATFORMS[$value] ?? '—';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }
}
