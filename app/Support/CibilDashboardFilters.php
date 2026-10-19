<?php

namespace App\Support;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The period / bureau / request type / file filters shared by the CIBIL
 * Dashboard and the Employee Activity page (Filament page filters, stored
 * in the page's $filters). Every figure on those screens is counted from
 * cibil_reports rows narrowed by apply() — nothing is estimated.
 */
final class CibilDashboardFilters
{
    /** @var array<string, string> */
    public const array PERIODS = [
        'all' => 'All time',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'previous_month' => 'Previous month',
        'custom' => 'Custom range',
    ];

    /** @var array<string, string> */
    public const array FETCHED_FOR = [
        'customer' => 'Customers',
        'lead' => 'Leads (My Leads + Assigned)',
    ];

    /**
     * @return list<Select|DatePicker>
     */
    public static function components(): array
    {
        return [
            Select::make('period')
                ->label('Period')
                ->options(self::PERIODS)
                ->default('all')
                ->searchable(false)
                ->selectablePlaceholder(false),

            DatePicker::make('from')
                ->label('From')
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),

            DatePicker::make('until')
                ->label('Until')
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),

            Select::make('bureau')
                ->label('Bureau')
                ->options(CreditBureau::options())
                ->placeholder('Both bureaus')
                ->searchable(false),

            Select::make('request_type')
                ->label('Request')
                ->options(CibilRequestType::options())
                ->placeholder('All requests')
                ->searchable(false),

            Select::make('fetched_for')
                ->label('Fetched for')
                ->options(self::FETCHED_FOR)
                ->placeholder('Customers and leads')
                ->searchable(false),
        ];
    }

    /**
     * The chosen period as [from, until] (either may be null: open-ended).
     *
     * @param  array<string, mixed>|null  $filters
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public static function range(?array $filters): array
    {
        $now = now();

        return match ($filters['period'] ?? 'all') {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'previous_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [
                filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : null,
                filled($filters['until'] ?? null) ? Carbon::parse($filters['until'])->endOfDay() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * Narrows a cibil_reports query to the filters. $table is the alias the
     * rows are queried under (for correlated subqueries).
     *
     * @param  Builder<CibilReport>  $query
     * @param  array<string, mixed>|null  $filters
     * @return Builder<CibilReport>
     */
    public static function apply(Builder $query, ?array $filters, string $table = 'cibil_reports'): Builder
    {
        [$from, $until] = self::range($filters);
        $bureau = CreditBureau::tryFrom((string) ($filters['bureau'] ?? ''));
        $type = CibilRequestType::tryFrom((string) ($filters['request_type'] ?? ''));

        return $query
            ->when($from, fn (Builder $query, Carbon $from): Builder => $query->where("{$table}.created_at", '>=', $from))
            ->when($until, fn (Builder $query, Carbon $until): Builder => $query->where("{$table}.created_at", '<=', $until))
            ->when($bureau, fn (Builder $query, CreditBureau $bureau): Builder => $query->where("{$table}.bureau", $bureau))
            ->when($type, fn (Builder $query, CibilRequestType $type): Builder => $query->where("{$table}.request_type", $type))
            ->when(($filters['fetched_for'] ?? null) === 'customer', fn (Builder $query): Builder => $query
                ->whereNotNull("{$table}.customer_id")->whereNull("{$table}.lead_id")->whereNull("{$table}.customer_assignment_id"))
            ->when(($filters['fetched_for'] ?? null) === 'lead', fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->whereNotNull("{$table}.lead_id")->orWhereNotNull("{$table}.customer_assignment_id")));
    }
}
