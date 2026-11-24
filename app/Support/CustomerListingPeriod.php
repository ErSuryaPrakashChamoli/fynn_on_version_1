<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Which customers the My Customers listing (and its "Export Customers")
 * shows for the topbar period and the journey status picked beside the
 * export button (user decision 2026-11-24 — before this, files not yet
 * disbursed were listed under every month, so no two totals agreed):
 *
 *  - "All statuses" and every stage but Disbursed: files ADDED in the
 *    period (created_at), so the months add up to the year and to all time;
 *  - "Disbursed": files DISBURSED in the period (disbursal_date).
 *
 * A file added in Sep 2026 and disbursed in Nov 2026 is therefore listed
 * under Sep 2026 with all statuses, and under Nov 2026 with Disbursed.
 */
class CustomerListingPeriod
{
    public const ALL_STATUSES = 'all';

    /** The journey_status a disbursed file holds (shown as "Disbursed"). */
    public const DISBURSED = 'sanctioned';

    /**
     * Every stored journey_status, in journey order, labelled as the
     * listing's Journey column shows it.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'not_started' => 'Not started',
            'sfl' => 'SFL',
            'underwriting' => 'Underwriting',
            'approved' => 'Approved',
            'not_approved' => 'Not approved',
            self::DISBURSED => 'Disbursed',
            'carry_forward' => 'Carry forward',
            'dropped' => 'Dropped',
        ];
    }

    /**
     * The status choices beside "Export Customers": all statuses first.
     *
     * @return array<string, string>
     */
    public static function statusChoices(): array
    {
        return [self::ALL_STATUSES => 'All statuses'] + self::statusOptions();
    }

    /**
     * A known journey_status, or "all" for anything else (a stale or
     * hand-edited URL).
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
        [$start, $end] = SelectedMonth::range();
        $status = self::normalizeStatus($status);
        $createdAt = $query->qualifyColumn('created_at');

        if ($status === self::ALL_STATUSES) {
            return $query->whereBetween($createdAt, [$start, $end]);
        }

        $query->where($query->qualifyColumn('journey_status'), $status);

        if ($status !== self::DISBURSED) {
            return $query->whereBetween($createdAt, [$start, $end]);
        }

        $disbursalDate = $query->qualifyColumn('disbursal_date');

        // Files disbursed before disbursal_date existed (2026-09-02) may have
        // none; they fall back to the date added rather than vanish.
        return $query->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull($disbursalDate)
                // A date column: compare whole days, so the first and last
                // day of the period count on every database.
                ->whereBetween($disbursalDate, [$start->toDateString(), $end->toDateString()]))
            ->orWhere(fn (Builder $query): Builder => $query
                ->whereNull($disbursalDate)
                ->whereBetween($createdAt, [$start, $end])));
    }

    /**
     * One line above the listing saying what it holds, e.g. "SFL files
     * added in Nov 2026".
     */
    public static function describe(?string $status): string
    {
        $status = self::normalizeStatus($status);

        if (SelectedMonth::mode() === SelectedMonth::MODE_ALL) {
            return $status === self::ALL_STATUSES
                ? 'All customer files, every status.'
                : 'All '.self::statusLabel($status).' files.';
        }

        $period = SelectedMonth::label();

        return match ($status) {
            self::ALL_STATUSES => "Files added in {$period}, every status. Pick Disbursed to see files disbursed in {$period}.",
            self::DISBURSED => "Files disbursed in {$period}.",
            default => self::statusLabel($status)." files added in {$period}.",
        };
    }
}
