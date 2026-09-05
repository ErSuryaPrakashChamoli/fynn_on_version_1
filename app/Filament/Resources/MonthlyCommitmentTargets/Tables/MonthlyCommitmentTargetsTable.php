<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets\Tables;

use App\Enums\CommitmentStage;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MonthlyCommitmentTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('employee.emp_name')
                    ->label('Employee')
                    ->description(fn (MonthlyCommitmentTarget $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_id')
                    ->label('Employee ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('month')
                    ->label('Month')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('stage')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn (CommitmentStage $state): string => $state->label())
                    ->color(fn (CommitmentStage $state): array => Color::hex($state->hex()))
                    ->sortable(),

                TextColumn::make('target_amount')
                    ->label('Target (₹)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, MonthlyCommitmentTarget $record): string => $record->stage->isCount()
                        ? '—'
                        : '₹'.indianCurrencyFormat($state))
                    ->sortable(),

                TextColumn::make('target_count')
                    ->label('Target OTPs')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, MonthlyCommitmentTarget $record): string => $record->stage->isCount()
                        ? number_format((int) $state)
                        : '—')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime('d M Y, H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->options(CommitmentStage::commitableOptions()),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'emp_name'),

                SelectFilter::make('designation')
                    ->label('Role')
                    ->options(Employee::designationOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('designation', $data['value']))
                        : $query),

                Filter::make('current_month')
                    ->label('This month only')
                    ->query(fn (Builder $query): Builder => $query->whereDate('month', Carbon::today()->startOfMonth()->toDateString())),
            ])
            // A Manager can see a Team Leader's target row (it's in their
            // tree) but only the Admin line may rewrite it, so every write
            // action is gated per record — see
            // MonthlyCommitmentTargetResource::canEdit()/canDelete().
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MonthlyCommitmentTarget $record): bool => MonthlyCommitmentTargetResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (MonthlyCommitmentTarget $record): bool => MonthlyCommitmentTargetResource::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
