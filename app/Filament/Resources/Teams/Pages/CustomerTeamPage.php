<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Customer;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Tables;
// use Filament\Tables\Contracts\HasTable;
// use Filament\Tables\Concerns\InteractsWithTable;

use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
                    ->searchable()
                    ->sortable(),

                // Tables\Columns\TextColumn::make('mobile_no'),
                Tables\Columns\TextColumn::make('mobile_no')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (blank($state)) {
                            return '-';
                        }

                        return 'XXXXXX'.substr($state, -4);
                    }),

                // Tables\Columns\TextColumn::make('journey_status')
                //     ->badge(),

                Tables\Columns\TextColumn::make('journey_status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => match ($state) {
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
                    ->color(fn ($state) => match ($state) {
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
                    ->money('INR')
                    ->sortable(),

                // Tables\Columns\TextColumn::make('employee.emp_name')
                //     ->label('Caller'),

                Tables\Columns\TextColumn::make('employee.emp_name')
                    ->label('Employee Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.emp_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.designation')
                    ->label('Designation')
                    ->formatStateUsing(fn ($state) => Employee::designationOptions()[$state] ?? 'Unknown')
                    ->badge()
                    ->sortable(),

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
                    ->multiple()
                    ->visible(fn () => $this->record->designation !== Employee::DESIGNATION_CALLER)
                    ->options(function () {
                        return Employee::query()
                            ->whereIn(
                                'id',
                                HierarchyHelper::callerIds($this->record)
                            )
                            ->orderBy('emp_name')
                            ->get(['id', 'emp_name', 'emp_id'])
                            ->mapWithKeys(fn (Employee $employee): array => [
                                $employee->id => EmployeeOptions::label($employee),
                            ])
                            ->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['values'] ?? null),
                            fn (Builder $query) => $query->whereIn('employee_id', $data['values'])
                        );
                    }),

                SelectFilter::make('month')
                    ->label('Month')
                    ->schema([
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
                            fn (Builder $query) => $query->whereMonth('created_at', $data['month'])
                        );
                    }),

                SelectFilter::make('year')
                    ->label('Year')
                    ->schema([
                        Select::make('year')
                            ->options(
                                collect(range(now()->year, now()->year - 10))
                                    ->mapWithKeys(fn ($year) => [$year => $year])
                                    ->toArray()
                            )
                            ->placeholder('All Years'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['year'] ?? null),
                            fn (Builder $query) => $query->whereYear('created_at', $data['year'])
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
