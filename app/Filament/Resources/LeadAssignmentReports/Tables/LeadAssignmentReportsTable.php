<?php

namespace App\Filament\Resources\LeadAssignmentReports\Tables;

use App\Filament\Exports\LeadAssignmentReportExporter;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use App\Support\SelectedMonth;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadAssignmentReportsTable
{
    /**
     * Every funnel count is additionally constrained to assignments made
     * during the globally selected month, so the whole funnel reflects
     * "assignments in that month" rather than an all-time total.
     */
    public static function funnelWithCount(): array
    {
        $inSelectedMonth = fn (Builder $q) => $q->whereBetween('created_at', SelectedMonth::range());

        return [
            'assignmentsReceived as assigned_count' => $inSelectedMonth,
            'assignmentsReceived as opened_count' => fn (Builder $q) => $inSelectedMonth($q)->where('opens_count', '>', 0),
            'assignmentsReceived as contacted_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer.followUps'),
            'assignmentsReceived as eligible_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'eligible')),
            'assignmentsReceived as not_eligible_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'not_eligible')),
            'assignmentsReceived as sfl_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'sfl')),
            'assignmentsReceived as underwriting_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'underwriting')),
            'assignmentsReceived as approved_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'approved')),
            'assignmentsReceived as disbursed_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'sanctioned')),
            'assignmentsReceived as completed_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'completed')),
            'assignmentsReceived as carry_forward_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'carry_forward')),
            'assignmentsReceived as dropped_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'dropped')),
            'assignmentsReceived as not_approved_count' => fn (Builder $q) => $inSelectedMonth($q)->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'not_approved')),
        ];
    }

    protected static function percentOf(string $count): \Closure
    {
        return function (Employee $record) use ($count): string {
            if (! $record->assigned_count) {
                return '0%';
            }

            return round(($record->{$count} / $record->assigned_count) * 100).'%';
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(static::funnelWithCount()))
            ->defaultSort('assigned_count', 'desc')
            ->columns([
                TextColumn::make('emp_name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('emp_id')
                    ->label('Emp ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('designation')
                    ->label('Role')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state)
                    ->badge()
                    ->sortable(),

                TextColumn::make('assigned_count')
                    ->label('Assigned')
                    ->sortable(),

                TextColumn::make('opened_count')
                    ->label('Opened')
                    ->sortable()
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('opened_count')($record).')'),

                TextColumn::make('contacted_count')
                    ->label('Contacted')
                    ->sortable()
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('contacted_count')($record).')'),

                TextColumn::make('approved_count')
                    ->label('Approved')
                    ->sortable()
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('approved_count')($record).')'),

                TextColumn::make('disbursed_count')
                    ->label('Disbursed')
                    ->sortable()
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (".static::percentOf('disbursed_count')($record).')'),

                TextColumn::make('eligible_count')
                    ->label('Eligible')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('not_eligible_count')
                    ->label('Not Eligible')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sfl_count')
                    ->label('SFL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('underwriting_count')
                    ->label('Underwriting')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_count')
                    ->label('Completed')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('carry_forward_count')
                    ->label('Carry Forward')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('dropped_count')
                    ->label('Dropped')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('not_approved_count')
                    ->label('Not Approved')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('id')
                    ->label('User')
                    ->multiple()
                    ->options(fn (): array => Employee::query()
                        ->whereHas('assignmentsReceived')
                        ->orderBy('emp_name')
                        ->get(['id', 'emp_name', 'emp_id'])
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => EmployeeOptions::label($employee),
                        ])
                        ->all()),

                SelectFilter::make('designation')
                    ->label('Role')
                    ->multiple()
                    ->options(fn (): array => Employee::designationOptions()),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeadAssignmentReportExporter::class)
                    ->label('Download Report'),
            ]);
    }
}
