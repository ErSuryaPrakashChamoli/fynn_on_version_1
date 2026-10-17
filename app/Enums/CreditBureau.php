<?php

namespace App\Enums;

/**
 * Which credit bureau a request goes to. The user picks it first, before
 * anything else on the request; both bureaus are always offered, with every
 * request type. Each bureau has its own Surepass endpoints and token
 * (config/cibil.php) — a bureau or type whose API is not set up yet fails
 * as "not connected" when sent — and reports from one bureau never stand in
 * for the other. Rows that predate the choice were CIBIL requests.
 */
enum CreditBureau: string
{
    case Cibil = 'cibil';
    case Experian = 'experian';

    public function label(): string
    {
        return match ($this) {
            self::Cibil => 'CIBIL',
            self::Experian => 'Experian',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Cibil => 'primary',
            self::Experian => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cibil => 'heroicon-o-building-library',
            self::Experian => 'heroicon-o-globe-alt',
        };
    }

    /**
     * This bureau's provider settings in config/cibil.php.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return (array) config(match ($this) {
            self::Cibil => 'cibil.surepass',
            self::Experian => 'cibil.experian',
        }, []);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $bureau): array => [$bureau->value => $bureau->label()])
            ->all();
    }
}
