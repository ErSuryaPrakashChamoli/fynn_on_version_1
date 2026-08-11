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
use App\Models\Customer;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Select;




class CustomerTeamPage extends Page implements HasTable
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



                Customer::query()
                    ->with('employee')
                    ->whereIn(
                        'employee_id',
                        HierarchyHelper::callerIds($this->record)
                    )
            )
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->searchable(),

                // Tables\Columns\TextColumn::make('mobile_no'),
                Tables\Columns\TextColumn::make('mobile_no')
                    ->formatStateUsing(function ($state) {
                        if (blank($state)) {
                            return '-';
                        }

                        return 'XXXXXX' . substr($state, -4);
                    }),

                // Tables\Columns\TextColumn::make('journey_status')
                //     ->badge(),

                Tables\Columns\TextColumn::make('journey_status')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        'sanctioned' => 'Sanctioned',
                        'disbursed' => 'Disbursed',
                        'not_approved' => 'Not Approved',
                        'carry_forward' => 'Carry Forward',
                        'dropped' => 'Dropped',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->color(fn($state) => match ($state) {
                        'sfl' => 'gray',
                        'underwriting' => 'warning',
                        'approved' => 'info',
                        'sanctioned' => 'primary',
                        'disbursed' => 'success',
                        'carry_forward' => 'warning',
                        'dropped', 'not_approved' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('approved_loan_amount')
                    ->money('INR'),


                // Tables\Columns\TextColumn::make('employee.emp_name')
                //     ->label('Caller'),

                Tables\Columns\TextColumn::make('employee.emp_name')
                    ->label('Employee Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.emp_id')
                    ->label('Employee ID'),

                Tables\Columns\TextColumn::make('employee.designation')
                    ->label('Designation')
                    ->formatStateUsing(fn($state) => Employee::designationOptions()[$state] ?? 'Unknown')
                    ->badge(),

            ])
            ->filters([

                SelectFilter::make('journey_status')
                    ->label('Journey Status')
                    ->options([
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        'sanctioned' => 'Sanctioned',
                        'disbursed' => 'Disbursed',
                        'not_approved' => 'Not Approved',
                        'carry_forward' => 'Carry Forward',
                        'dropped' => 'Dropped',
                    ])
                    ->searchable()
                    ->preload(),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->visible(fn() => $this->record->designation !== Employee::DESIGNATION_CALLER)
                    ->options(function () {
                        return Employee::query()
                            ->whereIn(
                                'id',
                                HierarchyHelper::callerIds($this->record)
                            )
                            ->orderBy('emp_name')
                            ->pluck('emp_name', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['value'] ?? null),
                            fn(Builder $query) => $query->where('employee_id', $data['value'])
                        );
                    }),

                SelectFilter::make('month')
                    ->label('Month')
                    ->form([
                        Select::make('month')
                            ->options([
                                1 => 'January',
                                2 => 'February',
                                3 => 'March',
                                4 => 'April',
                                5 => 'May',
                                6 => 'June',
                                7 => 'July',
                                8 => 'August',
                                9 => 'September',
                                10 => 'October',
                                11 => 'November',
                                12 => 'December',
                            ])
                            ->placeholder('All Months'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['month'] ?? null),
                            fn(Builder $query) => $query->whereMonth('created_at', $data['month'])
                        );
                    }),

                SelectFilter::make('year')
                    ->label('Year')
                    ->form([
                        Select::make('year')
                            ->options(
                                collect(range(now()->year, now()->year - 10))
                                    ->mapWithKeys(fn($year) => [$year => $year])
                                    ->toArray()
                            )
                            ->placeholder('All Years'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['year'] ?? null),
                            fn(Builder $query) => $query->whereYear('created_at', $data['year'])
                        );
                    }),
            ]);
    }

    public function getTitle(): string
    {
        return "{$this->record->emp_name} - Customer List";
    }


    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [
            TeamResource::getUrl() => 'Teams',
        ];

        foreach (HierarchyHelper::breadcrumb($this->record) as $item) {
            $breadcrumbs[$item['url'] ?? '#'] = $item['label'];
        }

        $breadcrumbs['#customers'] = 'Customers';

        return $breadcrumbs;
    }
}
