<?php

namespace App\Enums;

/**
 * Outcome of a daily commitment. While the day is still running a
 * commitment is InProgress; from the next day onward it settles into
 * Met / Overachieved / Failed.
 */
enum CommitmentResult: string
{
    case InProgress = 'in_progress';
    case Met = 'met';
    case Overachieved = 'overachieved';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            self::Met => 'Met',
            self::Overachieved => 'Overachieved',
            self::Failed => 'Failed',
        };
    }

    public function chipClasses(): string
    {
        return match ($this) {
            self::InProgress => 'bg-yellow-100 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/15 dark:text-yellow-300 dark:ring-yellow-400/30',
            self::Met => 'bg-green-100 text-green-700 ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30',
            self::Overachieved => 'bg-emerald-600 text-white ring-emerald-700/20 dark:bg-emerald-500 dark:text-white dark:ring-emerald-400/30',
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
     * Settle a commitment: still open while $date is today, otherwise
     * judged against the target.
     */
    public static function decide(float $target, float $achieved, bool $dayClosed): self
    {
        if ($target <= 0) {
            return $dayClosed ? self::Met : self::InProgress;
        }

        if ($achieved > $target) {
            return self::Overachieved;
        }

        if ($achieved >= $target) {
            return self::Met;
        }

        return $dayClosed ? self::Failed : self::InProgress;
    }
}
