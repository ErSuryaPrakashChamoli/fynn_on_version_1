<?php

namespace App\Filament\Resources\LeadAssignmentReports\Tables;

use App\Filament\Exports\LeadAssignmentReportExporter;
use App\Models\Employee;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadAssignmentReportsTable
{
    public static function funnelWithCount(): array
    {
        return [
            'assignmentsReceived as assigned_count',
            'assignmentsReceived as opened_count' => fn (Builder $q) => $q->where('opens_count', '>', 0),
            'assignmentsReceived as contacted_count' => fn (Builder $q) => $q->whereHas('customer.followUps'),
            'assignmentsReceived as eligible_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'eligible')),
            'assignmentsReceived as not_eligible_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('eligibility_status', 'not_eligible')),
            'assignmentsReceived as sfl_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'sfl')),
            'assignmentsReceived as underwriting_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'underwriting')),
            'assignmentsReceived as approved_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'approved')),
            'assignmentsReceived as disbursed_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'sanctioned')),
            'assignmentsReceived as completed_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'completed')),
            'assignmentsReceived as carry_forward_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'carry_forward')),
            'assignmentsReceived as dropped_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'dropped')),
            'assignmentsReceived as not_approved_count' => fn (Builder $q) => $q->whereHas('customer', fn (Builder $q2) => $q2->where('journey_status', 'not_approved')),
        ];
    }

    protected static function percentOf(string $count): \Closure
    {
        return function (Employee $record) use ($count): string {
            if (! $record->assigned_count) {
                return '0%';
            }

            return round(($record->{$count} / $record->assigned_count) * 100) . '%';
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

                TextColumn::make('designation')
                    ->label('Role')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? $state)
                    ->badge(),

                TextColumn::make('assigned_count')
                    ->label('Assigned')
                    ->sortable(),

                TextColumn::make('opened_count')
                    ->label('Opened')
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (" . static::percentOf('opened_count')($record) . ')'),

                TextColumn::make('contacted_count')
                    ->label('Contacted')
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (" . static::percentOf('contacted_count')($record) . ')'),

                TextColumn::make('approved_count')
                    ->label('Approved')
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (" . static::percentOf('approved_count')($record) . ')'),

                TextColumn::make('disbursed_count')
                    ->label('Disbursed')
                    ->formatStateUsing(fn ($state, Employee $record) => "{$state} (" . static::percentOf('disbursed_count')($record) . ')'),

                TextColumn::make('eligible_count')
                    ->label('Eligible')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('not_eligible_count')
                    ->label('Not Eligible')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sfl_count')
                    ->label('SFL')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('underwriting_count')
                    ->label('Underwriting')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_count')
                    ->label('Completed')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('carry_forward_count')
                    ->label('Carry Forward')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('dropped_count')
                    ->label('Dropped')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('not_approved_count')
                    ->label('Not Approved')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('id')
                    ->label('User')
                    ->options(
                        Employee::query()
                            ->whereHas('assignmentsReceived')
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id')
                    )
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeadAssignmentReportExporter::class)
                    ->label('Download Report'),
            ]);
    }
}
