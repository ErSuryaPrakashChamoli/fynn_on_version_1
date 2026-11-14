<?php

namespace App\Services;

use App\Models\DataDownloadAuthenticator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FAQRCode\Google2FA;
use PragmaRX\Google2FAQRCode\QRCode\Chillerlan;
use RuntimeException;
use SensitiveParameter;

/**
 * The owner's approval for Leads / Customers data downloads.
 *
 * The owner sets up an authenticator app (Google / Microsoft Authenticator)
 * once, from the Data Download page. Every download an Admin starts then
 * needs the 6-digit code that app shows at that moment, so the owner
 * approves each download by reading the code out. A code approves one
 * download only: the TOTP step of the last code used is stored and older
 * or equal steps are refused.
 *
 * Replacing the authenticator needs a current code from the existing one,
 * so an Admin cannot swap in their own phone. If the owner's phone is lost,
 * `php artisan data-download:reset-authenticator` (server access) clears it
 * and the owner sets it up again.
 *
 * Wrong codes are throttled per login, and setup, replacement, removal,
 * lockouts and every approved download are written to the activity log
 * under LOG_NAME.
 */
class DataDownloadAuthenticatorService
{
    public const string LOG_NAME = 'data-download';

    /**
     * Codes up to this many 30-second steps either side of the current one
     * are accepted, to allow for the owner reading the code out by phone.
     */
    public const int CODE_WINDOW = 4;

    public const int MAX_FAILED_ATTEMPTS = 5;

    public const int LOCKOUT_SECONDS = 900;

    public const string ACCOUNT_LABEL = 'Data download approval';

    public function __construct(private readonly Google2FA $google2fa) {}

    public function current(): ?DataDownloadAuthenticator
    {
        return DataDownloadAuthenticator::query()->first();
    }

    public function isConfigured(): bool
    {
        return DataDownloadAuthenticator::query()->exists();
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * The QR code the owner scans, as an SVG data URI for an <img>. Pinned
     * to the chillerlan renderer Filament ships with, which always returns
     * a data URI (Bacon, if ever installed, would return raw markup).
     */
    public function qrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        return $this->google2fa
            ->setQRCodeService(new Chillerlan)
            ->getQRCodeInline((string) config('app.name'), self::ACCOUNT_LABEL, $secret);
    }

    /**
     * Whether $code is valid for a secret that is still being set up.
     */
    public function codeMatchesSecret(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        return $this->matchingTimestep($secret, $code, 0) !== null;
    }

    /**
     * Whether $code would approve a download right now, without using it up.
     */
    public function accepts(#[SensitiveParameter] string $code): bool
    {
        $authenticator = $this->current();

        return $authenticator !== null
            && $this->matchingTimestep($authenticator->secret, $code, $authenticator->last_used_timestep ?? 0) !== null;
    }

    /**
     * Uses $code up. True exactly once per code; false when it is wrong,
     * expired or has already approved something.
     */
    public function consume(#[SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($code): bool {
            $authenticator = DataDownloadAuthenticator::query()->lockForUpdate()->first();

            if ($authenticator === null) {
                return false;
            }

            $timestep = $this->matchingTimestep($authenticator->secret, $code, $authenticator->last_used_timestep ?? 0);

            if ($timestep === null) {
                return false;
            }

            $authenticator->forceFill([
                'last_used_timestep' => $timestep,
                'last_used_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * First-time setup. Refused once an authenticator exists — replacing
     * one goes through replace(), which needs a code from the current one.
     */
    public function setUp(string $ownerName, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $confirmationCode, User $by): DataDownloadAuthenticator
    {
        return DB::transaction(function () use ($ownerName, $secret, $confirmationCode, $by): DataDownloadAuthenticator {
            if (DataDownloadAuthenticator::query()->lockForUpdate()->exists()) {
                throw new RuntimeException('The owner authenticator is already set up. Replace it with a code from the current one.');
            }

            $authenticator = $this->store(new DataDownloadAuthenticator, $ownerName, $secret, $confirmationCode, $by);

            $this->log('Set up the owner authenticator for data downloads', $by, ['owner_name' => $authenticator->owner_name]);

            return $authenticator;
        });
    }

    /**
     * Moves approvals to a new authenticator. $currentCode must come from
     * the existing one, so only its holder can hand approval over.
     */
    public function replace(#[SensitiveParameter] string $currentCode, string $ownerName, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $confirmationCode, User $by): DataDownloadAuthenticator
    {
        return DB::transaction(function () use ($currentCode, $ownerName, $secret, $confirmationCode, $by): DataDownloadAuthenticator {
            if (! $this->consume($currentCode)) {
                throw new RuntimeException('The code from the current authenticator is wrong, expired or already used.');
            }

            $authenticator = DataDownloadAuthenticator::query()->lockForUpdate()->firstOrFail();
            $previousOwner = $authenticator->owner_name;

            $authenticator = $this->store($authenticator, $ownerName, $secret, $confirmationCode, $by);

            $this->log('Replaced the owner authenticator for data downloads', $by, [
                'previous_owner_name' => $previousOwner,
                'owner_name' => $authenticator->owner_name,
            ]);

            return $authenticator;
        });
    }

    /**
     * Removes the authenticator (owner's phone lost). Downloads stay
     * blocked until the owner sets a new one up.
     */
    public function remove(?User $by = null): bool
    {
        $authenticator = $this->current();

        if ($authenticator === null) {
            return false;
        }

        $authenticator->delete();

        $this->log('Removed the owner authenticator for data downloads', $by, ['owner_name' => $authenticator->owner_name]);

        return true;
    }

    public function isLockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_FAILED_ATTEMPTS);
    }

    public function lockoutMinutesRemaining(User $user): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn($this->throttleKey($user)) / 60));
    }

    public function recordFailedAttempt(User $user): void
    {
        RateLimiter::hit($this->throttleKey($user), self::LOCKOUT_SECONDS);

        if ($this->isLockedOut($user)) {
            $this->log('Locked out of data downloads after too many wrong owner codes', $user);
        }
    }

    public function clearFailedAttempts(User $user): void
    {
        RateLimiter::clear($this->throttleKey($user));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $description, ?User $by, array $properties = []): void
    {
        activity(self::LOG_NAME)
            ->causedBy($by)
            ->withProperties($properties)
            ->log($description);
    }

    private function store(DataDownloadAuthenticator $authenticator, string $ownerName, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $confirmationCode, User $by): DataDownloadAuthenticator
    {
        $timestep = $this->matchingTimestep($secret, $confirmationCode, 0);

        if ($timestep === null) {
            throw new RuntimeException('The code does not match the new authenticator. Scan the QR code again and enter the code it shows.');
        }

        // The confirmation code was typed in front of whoever is at the
        // screen, so it is marked used and cannot approve a download.
        $authenticator->forceFill([
            'owner_name' => trim($ownerName),
            'secret' => $secret,
            'last_used_timestep' => $timestep,
            'last_used_at' => null,
            'configured_by' => $by->getKey(),
            'configured_at' => now(),
        ])->save();

        return $authenticator;
    }

    private function throttleKey(User $user): string
    {
        return 'data-download-approval:'.$user->getKey();
    }

    /**
     * The TOTP step $code belongs to, if it is valid and newer than
     * $lastUsedTimestep; null otherwise.
     */
    private function matchingTimestep(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $lastUsedTimestep): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return null;
        }

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, $lastUsedTimestep, self::CODE_WINDOW);

        return is_int($timestep) ? $timestep : null;
    }
}
