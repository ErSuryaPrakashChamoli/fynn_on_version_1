<?php

namespace Database\Seeders\Demo\Concerns;

use Illuminate\Support\Carbon;

/**
 * State shared by the demo seeders for one seeding run: the real clock the
 * replayed history must stay behind, the password every demo login shares,
 * the login table printed at the end, and the counters that keep every fake
 * identifier unique.
 *
 * Fake identifiers are deliberately unmistakable: mobiles in the 90000
 * block, PANs in the ZZ series, e-mails on the reserved .test domain.
 */
class DemoSeedState
{
    public const EMAIL_DOMAIN = 'demo-fynnon.test';

    protected static ?Carbon $realNow = null;

    protected static ?string $password = null;

    protected static int $mobileSequence = 0;

    protected static int $panSequence = 0;

    /**
     * @var array<int, array{role: string, name: string, email: string}>
     */
    protected static array $logins = [];

    public static function start(): void
    {
        static::$realNow = Carbon::now();
        static::$password = null;
        static::$mobileSequence = 0;
        static::$panSequence = 0;
        static::$logins = [];
    }

    public static function realNow(): Carbon
    {
        return (static::$realNow ??= Carbon::now())->copy();
    }

    public static function setPassword(string $password): void
    {
        static::$password = $password;
    }

    public static function password(): string
    {
        return (string) static::$password;
    }

    public static function recordLogin(string $role, string $name, string $email): void
    {
        static::$logins[] = ['role' => $role, 'name' => $name, 'email' => $email];
    }

    /**
     * @return array<int, array{role: string, name: string, email: string}>
     */
    public static function logins(): array
    {
        return static::$logins;
    }

    /**
     * A unique mobile inside the 90000 xxxxx block.
     */
    public static function nextMobile(): string
    {
        return '90000'.str_pad((string) ++static::$mobileSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * A unique PAN-shaped string in the reserved ZZ series (ZZ + 3 letters
     * + 4 digits + 1 letter), so it can never match an issued PAN.
     */
    public static function nextPan(): string
    {
        $sequence = ++static::$panSequence;
        $letters = range('A', 'Z');

        return 'ZZ'
            .$letters[intdiv($sequence, 676) % 26]
            .$letters[intdiv($sequence, 26) % 26]
            .$letters[$sequence % 26]
            .str_pad((string) ($sequence % 10000), 4, '0', STR_PAD_LEFT)
            .'Z';
    }

    public static function email(string $localPart): string
    {
        return strtolower($localPart).'@'.self::EMAIL_DOMAIN;
    }
}
