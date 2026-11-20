<?php

namespace App\Enums;

/**
 * The cashback declaration on a disbursed file (customers.cashback_option),
 * and the kind of cashback form raised for it. Cashback is promised to the
 * customer; Expected cashback is held against the file in case it is
 * needed and can be retrieved by the Team Leader or Manager later. Both
 * count as cashback in the achievement and incentive figures.
 */
enum CashbackType: string
{
    case None = 'none';
    case Cashback = 'cashback';
    case Expected = 'expected';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No cashback',
            self::Cashback => 'Cashback',
            self::Expected => 'Expected cashback',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Cashback => 'primary',
            self::Expected => 'info',
        };
    }

    public function needsForm(): bool
    {
        return $this !== self::None;
    }

    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? '—';
    }

    /**
     * The declaration choices on the disbursal step.
     *
     * @return array<string, string>
     */
    public static function declarationOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * What a cashback form can be raised for.
     *
     * @return array<string, string>
     */
    public static function formOptions(): array
    {
        return [
            self::Cashback->value => self::Cashback->label(),
            self::Expected->value => self::Expected->label(),
        ];
    }
}
