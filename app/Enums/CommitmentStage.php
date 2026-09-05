<?php

namespace App\Enums;

/**
 * The Daily Commitment module's stage vocabulary, and the single place
 * where it maps onto the existing LMS customer journey.
 *
 * The main journey is a ladder — Docs Received -> SFL -> Underwriting ->
 * Approved -> Disbursed — so reaching a stage implies every stage below
 * it (see rank()). Dropped and Rejected are outcomes, not rungs: a case
 * that went Approved -> Rejected still has a highest rank of Approved,
 * which is exactly why achievement is computed from the highest rank
 * reached rather than from the current journey_status.
 *
 * Otp is a count-based commitment (number of cases), not an amount one,
 * and sits outside the ladder entirely.
 *
 * Colours are fixed per stage and reused everywhere (badges, cards,
 * tables, charts) — see the .commitment-stage-* rules in the admin theme.
 */
enum CommitmentStage: string
{
    case DocsReceived = 'docs_received';
    case Sfl = 'sfl';
    case Underwriting = 'underwriting';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Otp = 'otp';
    case Dropped = 'dropped';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::DocsReceived => 'Docs Received',
            self::Sfl => 'SFL',
            self::Underwriting => 'Underwriting',
            self::Approved => 'Approved',
            self::Disbursed => 'Disbursed',
            self::Otp => 'No. of OTPs',
            self::Dropped => 'Dropped',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Position on the main journey ladder. Null for anything off the
     * ladder (OTP, Dropped, Rejected).
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::DocsReceived => 1,
            self::Sfl => 2,
            self::Underwriting => 3,
            self::Approved => 4,
            self::Disbursed => 5,
            default => null,
        };
    }

    /**
     * OTP commitments are counted, every other stage is an amount in ₹.
     */
    public function isCount(): bool
    {
        return $this === self::Otp;
    }

    /**
     * Tailwind utility classes for a stage chip. Kept as literal strings
     * so the theme's `@source '.../app/Enums/*'` scan can see them.
     */
    public function chipClasses(): string
    {
        return match ($this) {
            self::DocsReceived => 'bg-blue-100 text-blue-700 ring-blue-600/20 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-400/30',
            self::Sfl => 'bg-purple-100 text-purple-700 ring-purple-600/20 dark:bg-purple-500/15 dark:text-purple-300 dark:ring-purple-400/30',
            self::Underwriting => 'bg-orange-100 text-orange-700 ring-orange-600/20 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-400/30',
            self::Approved => 'bg-green-100 text-green-700 ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30',
            self::Disbursed => 'bg-teal-100 text-teal-700 ring-teal-600/20 dark:bg-teal-500/15 dark:text-teal-300 dark:ring-teal-400/30',
            self::Otp => 'bg-yellow-100 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/15 dark:text-yellow-300 dark:ring-yellow-400/30',
            self::Dropped => 'bg-gray-200 text-gray-700 ring-gray-500/20 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30',
            self::Rejected => 'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-400/30',
        };
    }

    /**
     * Solid hex for progress bars and chart segments.
     */
    public function hex(): string
    {
        return match ($this) {
            self::DocsReceived => '#3b82f6',
            self::Sfl => '#a855f7',
            self::Underwriting => '#f97316',
            self::Approved => '#22c55e',
            self::Disbursed => '#0d9488',
            self::Otp => '#eab308',
            self::Dropped => '#6b7280',
            self::Rejected => '#ef4444',
        };
    }

    /**
     * The ladder, lowest rung first.
     *
     * @return array<int, self>
     */
    public static function ladder(): array
    {
        return [self::DocsReceived, self::Sfl, self::Underwriting, self::Approved, self::Disbursed];
    }

    /**
     * Stages an employee may actually commit to: the ladder plus OTP.
     *
     * @return array<int, self>
     */
    public static function commitable(): array
    {
        return [...self::ladder(), self::Otp];
    }

    /**
     * Ladder stages an employee can declare on a fulfilment row.
     *
     * @return array<string, string>
     */
    public static function ladderOptions(): array
    {
        return collect(self::ladder())
            ->mapWithKeys(fn (self $stage): array => [$stage->value => $stage->label()])
            ->all();
    }

    /**
     * Terminal outcomes. These are never ladder positions — a case that
     * was approved and then rejected still counts as Approved.
     *
     * @return array<string, string>
     */
    public static function outcomeOptions(): array
    {
        return [
            self::Dropped->value => self::Dropped->label(),
            self::Rejected->value => self::Rejected->label(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function commitableOptions(): array
    {
        return collect(self::commitable())
            ->mapWithKeys(fn (self $stage): array => [$stage->value => $stage->label()])
            ->all();
    }

    /**
     * Every stage shown in the stage report — ladder plus both outcomes.
     *
     * @return array<int, self>
     */
    public static function reportable(): array
    {
        return [...self::ladder(), self::Dropped, self::Rejected];
    }
}
