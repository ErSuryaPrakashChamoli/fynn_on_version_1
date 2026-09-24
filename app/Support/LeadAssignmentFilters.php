<?php

namespace App\Support;

use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filters shared by the Assigned Leads listing and the Lead Assignment report,
 * so "assigned between", "template" and "assigned by" mean the same on both.
 *
 * The listing applies them to customer_assignments directly; the report reads
 * the same filter state and applies it inside its per-employee counts via
 * scopeAssignments().
 */
class LeadAssignmentFilters
{
    /**
     * The assigned-on window: the "Assigned On" filter when either end is set,
     * otherwise the month picked in the topbar.
     *
     * @param  array<string, mixed>|null  $filters  Table filter state.
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function assignedRange(?array $filters): array
    {
        $from = $filters['assigned_on']['assigned_from'] ?? null;
        $until = $filters['assigned_on']['assigned_until'] ?? null;

        if (blank($from) && blank($until)) {
            return SelectedMonth::range();
        }

        return [
            filled($from) ? Carbon::parse($from)->startOfDay() : Carbon::create(2000),
            filled($until) ? Carbon::parse($until)->endOfDay() : now()->endOfDay(),
        ];
    }

    /**
     * Applies the assigned-on window, template and assigned-by filters to a
     * customer_assignments query.
     *
     * @param  array<string, mixed>|null  $filters  Table filter state.
     */
    public static function scopeAssignments(Builder $query, ?array $filters): Builder
    {
        $templateIds = array_filter((array) ($filters['template']['values'] ?? []));
        $assignedByIds = array_filter((array) ($filters['assigned_by']['values'] ?? []));

        return $query
            ->whereBetween('customer_assignments.created_at', self::assignedRange($filters))
            ->when($templateIds, fn (Builder $query) => $query->whereIn('customer_assignments.ai_document_schema_id', $templateIds))
            ->when($assignedByIds, fn (Builder $query) => $query->whereIn('customer_assignments.assigned_by', $assignedByIds));
    }

    /**
     * "Assigned On" date range. When set it replaces the topbar month; the
     * window itself is applied through assignedRange() by each table.
     */
    public static function assignedOnFilter(): Filter
    {
        return Filter::make('assigned_on')
            ->label('Assigned On')
            ->schema([
                DatePicker::make('assigned_from')
                    ->label('Assigned From'),

                DatePicker::make('assigned_until')
                    ->label('Assigned To'),
            ])
            ->query(fn (Builder $query): Builder => $query)
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if ($data['assigned_from'] ?? null) {
                    $indicators[] = 'Assigned from: '.Carbon::parse($data['assigned_from'])->format('d M Y');
                }

                if ($data['assigned_until'] ?? null) {
                    $indicators[] = 'Assigned to: '.Carbon::parse($data['assigned_until'])->format('d M Y');
                }

                return $indicators;
            });
    }

    /**
     * @param  bool  $appliesToQuery  False on the report, where it is applied inside the counts instead.
     */
    public static function templateFilter(bool $appliesToQuery = true): SelectFilter
    {
        return SelectFilter::make('template')
            ->label('Template')
            ->multiple()
            ->options(fn (): array => AiDocumentSchema::query()->orderBy('name')->pluck('name', 'id')->all())
            ->query(fn (Builder $query, array $data): Builder => $appliesToQuery
                ? $query->when(filled($data['values'] ?? null), fn (Builder $query) => $query->whereIn('customer_assignments.ai_document_schema_id', $data['values']))
                : $query);
    }

    /**
     * @param  bool  $appliesToQuery  False on the report, where it is applied inside the counts instead.
     */
    public static function assignedByFilter(bool $appliesToQuery = true): SelectFilter
    {
        return SelectFilter::make('assigned_by')
            ->label('Assigned By')
            ->multiple()
            ->options(fn (): array => Employee::query()
                ->whereIn('id', CustomerAssignment::query()->select('assigned_by')->whereNotNull('assigned_by'))
                ->orderBy('emp_name')
                ->get(['id', 'emp_name', 'emp_id'])
                ->mapWithKeys(fn (Employee $employee): array => [$employee->id => EmployeeOptions::label($employee)])
                ->all())
            ->query(fn (Builder $query, array $data): Builder => $appliesToQuery
                ? $query->when(filled($data['values'] ?? null), fn (Builder $query) => $query->whereIn('customer_assignments.assigned_by', $data['values']))
                : $query);
    }
}
