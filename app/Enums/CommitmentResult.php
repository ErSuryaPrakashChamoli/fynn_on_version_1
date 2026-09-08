<?php

namespace App\Enums;

/**
 * Outcome of a daily commitment. While the day is still running a
 * commitment is InProgress; once the day is closed it settles into
 * Met / Overachieved / Partial / Failed.
 *
 * Partial is the "in parts" case: the whole number was delivered, but not
 * all of it at the stage that was committed to. ₹10L promised at Approval
 * and brought back as ₹7L Approval + ₹3L SFL is a partial day — neither a
 * clean pass nor a failure.
 */
enum CommitmentResult: string
{
    case InProgress = 'in_progress';
    case Met = 'met';
    case Overachieved = 'overachieved';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            self::Met => 'Met',
            self::Overachieved => 'Overachieved',
            self::Partial => 'Partially Met',
            self::Failed => 'Failed',
        };
    }

    public function chipClasses(): string
    {
        return match ($this) {
            self::InProgress => 'bg-yellow-100 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/15 dark:text-yellow-300 dark:ring-yellow-400/30',
            self::Met => 'bg-green-100 text-green-700 ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30',
            self::Overachieved => 'bg-emerald-600 text-white ring-emerald-700/20 dark:bg-emerald-500 dark:text-white dark:ring-emerald-400/30',
            self::Partial => 'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-400/30',
            self::Failed => 'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-400/30',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $result): array => [$result->value => $result->label()])
            ->all();
    }

    /**
     * Settle a commitment.
     *
     * $achieved is the business that landed AT OR ABOVE the committed
     * stage — the only thing that earns a clean pass. $totalAchieved adds
     * whatever came in below it: promise ₹10L at Approval, bring ₹7L
     * Approval + ₹3L SFL, and the day is Partial rather than Failed. Pass
     * it as null (or the same figure) where there is no ladder to fall
     * short on, e.g. an OTP count commitment.
     *
     * A day only settles once it is closed: while it is still running,
     * anything short of the promise stays In Progress rather than being
     * prematurely called a failure.
     */
    public static function decide(float $target, float $achieved, bool $dayClosed, ?float $totalAchieved = null): self
    {
        $totalAchieved = max($totalAchieved ?? $achieved, $achieved);

        if ($target <= 0) {
            return $dayClosed ? self::Met : self::InProgress;
        }

        if ($achieved > $target) {
            return self::Overachieved;
        }

        if ($achieved >= $target) {
            return self::Met;
        }

        if (! $dayClosed) {
            return self::InProgress;
        }

        // Short at the committed stage, but the number was made up from
        // lower rungs — credited as a partial day, never as a pass.
        return $totalAchieved >= $target ? self::Partial : self::Failed;
    }
}
