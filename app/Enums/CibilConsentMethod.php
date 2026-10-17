<?php

namespace App\Enums;

/**
 * How the customer gave consent for a bureau enquiry. A ticked box alone
 * proves nothing, so every request records how consent was obtained (and,
 * where there is one, a reference to the evidence) alongside the purpose,
 * the time and the employee who asked.
 */
enum CibilConsentMethod: string
{
    case RecordedCall = 'recorded_call';
    case Written = 'written';
    case Digital = 'digital';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::RecordedCall => 'Verbal, on a recorded call',
            self::Written => 'Signed consent form',
            self::Digital => 'Digital consent (OTP / e-sign / link)',
            self::Email => 'By email',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }
}
