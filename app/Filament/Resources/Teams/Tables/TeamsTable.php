<?php

namespace App\Filament\Resources\Teams\Tables;
use App\Filament\Resources\Teams\TeamResource;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

use App\Models\Employee;
use App\Support\HierarchyHelper;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                    TextColumn::make('emp_name')
                    ->searchable()
                    ->sortable(),

                  TextColumn::make('designation')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Employee::DESIGNATION_ADMIN => 'Admin',
                        Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
                        Employee::DESIGNATION_MANAGER => 'Manager',
                        Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
                        Employee::DESIGNATION_CALLER => 'Caller',
                        default => $state,
                    }),

                    TextColumn::make('superviser.emp_name')
                    ->label('Team Leader')
                    ->default('-'),

                    TextColumn::make('manager.emp_name')
                    ->default('-'),

                    TextColumn::make('cluster.emp_name')
                    ->label('Cluster Manager')
                    ->default('-'),
                //
            ])
            ->filters([
                //

                   SelectFilter::make('designation')
                    ->options([
                        Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
                        Employee::DESIGNATION_MANAGER => 'Manager',
                        Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
                        Employee::DESIGNATION_CALLER => 'Caller',
                    ]),

                SelectFilter::make('cluster_id')
                    ->label('Cluster Manager')
                    // ->relationship(
                    //     'cluster',
                    //     'emp_name',
                    //     fn ($query) => $query->whereIn(
                    //         'id',
                    //         HierarchyHelper::visibleEmployeeIds(auth()->user())
                    //     )
                    // )
                    ->relationship(
                        'superviser',
                        'emp_name',
                        fn ($query) => $query->whereIn(
                            'id',
                            HierarchyHelper::visibleEmployeeIds(auth()->user())
                        )
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('manager_id')
                    ->label('Manager')
                    ->relationship(
                        'manager',
                        'emp_name',
                        fn ($query) => $query->whereIn(
                            'id',
                            HierarchyHelper::visibleEmployeeIds(auth()->user())
                        )
                    )
                    ->searchable()
                    ->preload(),

                // SelectFilter::make('superviser_id')
                //     ->label('Team Leader')
                //     ->relationship(
                //         'supervisor',
                //         'emp_name',
                //         fn ($query) => $query->whereIn(
                //             'id',
                //             HierarchyHelper::visibleEmployeeIds(auth()->user())
                //         )
                //     )
                //     ->searchable()
                //     ->preload(),

            ])
            ->recordActions([
                // EditAction::make(),
                // Action::make('View Team')
                // ->icon('heroicon-o-eye')

                Action::make('View Team')
                ->icon('heroicon-o-eye')
                ->url(fn (Employee $record) => TeamResource::getUrl('view', [
                    'record' => $record,
                ]))
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
