<?php

namespace App\Support;

use App\Models\CustomerAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which leads the My Leads listing (and its "Export Leads") shows for the
 * topbar period and the status picked beside the export button (user
 * decision 2026-11-24, matching My Customers — CustomerListingPeriod):
 * the leads ADDED in the period (created_at), narrowed to one follow-up
 * status. "Other" holds leads whose status is none of the listed ones
 * (free text from old imports), so the statuses add up to all statuses.
 */
class LeadListingPeriod
{
    public const ALL_STATUSES = 'all';

    public const OTHER_STATUSES = 'other';

    /**
     * Every follow-up status a lead can be given, then "Other".
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return CustomerAssignment::FOLLOW_UP_STATUSES + [self::OTHER_STATUSES => 'Other'];
    }

    /**
     * The status choices beside "Export Leads": all statuses first.
     *
     * @return array<string, string>
     */
    public static function statusChoices(): array
    {
        return [self::ALL_STATUSES => 'All statuses'] + self::statusOptions();
    }

    /**
     * A known status, or "all" for anything else (a stale or hand-edited URL).
     */
    public static function normalizeStatus(?string $status): string
    {
        return array_key_exists((string) $status, self::statusOptions()) ? (string) $status : self::ALL_STATUSES;
    }

    public static function statusLabel(?string $status): string
    {
        return self::statusChoices()[self::normalizeStatus($status)];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, ?string $status = null): Builder
    {
        $status = self::normalizeStatus($status);
        $statusColumn = $query->qualifyColumn('status');

        $query->whereBetween($query->qualifyColumn('created_at'), SelectedMonth::range());

        return match ($status) {
            self::ALL_STATUSES => $query,
            self::OTHER_STATUSES => $query->where(fn (Builder $query): Builder => $query
                ->whereNull($statusColumn)
                ->orWhereNotIn($statusColumn, array_keys(CustomerAssignment::FOLLOW_UP_STATUSES))),
            default => $query->where($statusColumn, $status),
        };
    }

    /**
     * One line above the listing saying what it holds, e.g. "Interested
     * leads added in Nov 2026."
     */
    public static function describe(?string $status): string
    {
        $status = self::normalizeStatus($status);
        $subject = match ($status) {
            self::ALL_STATUSES => 'Leads',
            self::OTHER_STATUSES => 'Leads with any other status',
            default => self::statusLabel($status).' leads',
        };

        if (SelectedMonth::mode() === SelectedMonth::MODE_ALL) {
            return match ($status) {
                self::ALL_STATUSES => 'All leads, every status.',
                self::OTHER_STATUSES => 'All leads with any other status.',
                default => 'All '.self::statusLabel($status).' leads.',
            };
        }

        $period = SelectedMonth::label();

        return $status === self::ALL_STATUSES
            ? "Leads added in {$period}, every status."
            : "{$subject} added in {$period}.";
    }
}
