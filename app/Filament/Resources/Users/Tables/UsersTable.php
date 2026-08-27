<?php

namespace App\Filament\Resources\Users\Tables;

use App\Support\SelectedMonth;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->searchable(),

                TextColumn::make('employee.emp_name')
                    ->label('Employee'),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->badge()
                    ->label('Role'),

                // TextColumn::make('is_active')
                //     ->boolean(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25, 50, 100, 'all'])
            ->deferFilters(false)
            ->filters([
                Filter::make('created_in_selected_month')
                    ->label('Only users created up to this month')
                    ->toggle()
                    ->default(true)
                    ->query(fn (Builder $query) => $query->where('created_at', '<=', SelectedMonth::range()[1])),
            ])
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
