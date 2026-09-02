<?php

namespace App\Enums;

/**
 * Distinguishes which cases a Team Continuity / Backup Access rule applies
 * to. This is an audit/eligibility distinction evaluated against the rule's
 * own start_at — it is not a second enforcement engine: both branches are
 * still evaluated by the same CustomerJourneyAccessService check, live,
 * every time (see its class docblock for why "existing" and "new" don't
 * need separate code paths in this codebase).
 */
enum ContinuityCoverageType: string
{
    case Existing = 'existing';
    case New = 'new';
    case ExistingAndNew = 'existing_and_new';

    public function label(): string
    {
        return match ($this) {
            self::Existing => 'Existing Customers',
            self::New => 'New Customers',
            self::ExistingAndNew => 'Existing + New Customers',
        };
    }

    public function coversExisting(): bool
    {
        return $this !== self::New;
    }

    public function coversNew(): bool
    {
        return $this !== self::Existing;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
