<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\Page;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use App\Support\HierarchyHelper;

use Filament\Tables\Table;
use Filament\Tables;
// use Filament\Tables\Contracts\HasTable;
// use Filament\Tables\Concerns\InteractsWithTable;
use Illuminate\Database\Eloquent\Builde;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Actions\Action;




class ViewTeam extends Page implements HasTable
{
    use InteractsWithTable;

    public Collection $children;

    protected static string $resource = TeamResource::class;

    protected string $view = 'filament.resources.teams.pages.view-team';


    public Employee $record;

    public function mount(Employee $record): void
    {
        $this->record = $record;

    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                HierarchyHelper::children($this->record)
            )
            ->columns([
                Tables\Columns\TextColumn::make('emp_name')
                    ->searchable(),

                // Tables\Columns\TextColumn::make('designation')
                //     ->badge(),

                Tables\Columns\TextColumn::make('designation')
                    ->label('Position')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((string) $state) {
                        '1' => 'Admin',
                        '2' => 'Manager',
                        '3' => 'Team Leader',
                        '5' => 'Cluster Manager',
                        '7' => 'Caller',
                        default => 'Unknown',
                    }),
                Tables\Columns\TextColumn::make('emp_id'),

                Tables\Columns\TextColumn::make('mobile'),

                Tables\Columns\TextColumn::make('email'),
            ])
            ->recordActions([
                // Action::make('viewTeam')
                //     ->label('View Team')
                //     ->icon('heroicon-o-users')
                //     ->url(fn (Employee $record) => TeamResource::getUrl('view-team', [
                //         'record' => $record,
                //     ])),


        Action::make('viewTeam')
            ->label('View Team')
            ->icon('heroicon-o-users')
            ->visible(fn (Employee $record) => $record->designation !== Employee::DESIGNATION_CALLER)
            ->url(fn (Employee $record) => TeamResource::getUrl('view-team', [
                'record' => $record,
            ])),

            Action::make('viewCustomers')
                ->label('Customers')
                ->icon('heroicon-o-user-group')
                ->color('success')
                ->url(fn (Employee $record) => TeamResource::getUrl('view-customers', [
                    'record' => $record,
                ])),
            ]);
    }


    // public function getBreadcrumbs(): array
    // {
    //     $breadcrumbs = [
    //         TeamResource::getUrl() => 'Teams',
    //     ];

    //     foreach (HierarchyHelper::breadcrumb($this->record) as $item) {
    //         $breadcrumbs[$item['url'] ?? '#'] = $item['label'];
    //     }

    //     return $breadcrumbs;
    // }



    public function getBreadcrumbs(): array{
        $breadcrumbs = [
            TeamResource::getUrl() => 'Teams',
        ];

        foreach (HierarchyHelper::breadcrumb($this->record) as $item) {
            $breadcrumbs[$item['url']] = $item['label'];
        }

        return $breadcrumbs;
    }

    public function getTitle(): string{
            return "{$this->record->emp_name} - Team";
        }

}
