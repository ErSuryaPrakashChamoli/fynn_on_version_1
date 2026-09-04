<?php

namespace App\Filament\Resources\PendingManagerCases\Tables;

use App\Enums\JourneyModule;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Models\Customer;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\JourneySlaService;
use App\Support\EmployeeOptions;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PendingManagerCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_name')
                    ->label('Case Owner')
                    ->placeholder('Unassigned')
                    ->description(fn (Customer $record): ?string => $record->employee?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.emp_id')
                    ->label('Emp ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('original_manager')
                    ->label('Original Manager')
                    ->state(fn (Customer $record): string => app(CustomerJourneyAccessService::class)
                        ->naturalManagerFor($record)?->emp_name ?? 'Unassigned'),

                TextColumn::make('operational_manager')
                    ->label('Operational Manager')
                    ->state(function (Customer $record): string {
                        $accessService = app(CustomerJourneyAccessService::class);
                        $natural = $accessService->naturalManagerFor($record);
                        $operational = $accessService->resolveOperationalManager($record);

                        if (! $operational) {
                            return 'Unassigned';
                        }

                        return $natural && $natural->id === $operational->id
                            ? $operational->emp_name
                            : "{$operational->emp_name} (Backup)";
                    })
                    ->color(fn (Customer $record): string => app(CustomerJourneyAccessService::class)->naturalManagerFor($record)?->id
                        === app(CustomerJourneyAccessService::class)->resolveOperationalManager($record)?->id
                            ? 'gray'
                            : 'info'),

                TextColumn::make('module')
                    ->label('Journey Stage')
                    ->badge()
                    ->state(fn (Customer $record): string => JourneyModule::forCustomer($record)->label()),

                TextColumn::make('waiting_since')
                    ->label('Waiting Since')
                    ->state(fn (Customer $record) => JourneySlaService::stageEnteredAt($record))
                    ->dateTime('d M Y h:i A'),

                TextColumn::make('sla_status')
                    ->label('SLA Status')
                    ->badge()
                    ->state(function (Customer $record): string {
                        [$status] = self::slaState($record);

                        return $status;
                    })
                    ->color(function (Customer $record): string {
                        [, $color] = self::slaState($record);

                        return $color;
                    }),
            ])
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('journey_status')
                    ->label('Journey Status')
                    ->multiple()
                    ->options([
                        'sfl' => 'SFL',
                        'underwriting' => 'Underwriting',
                        'approved' => 'Approved',
                        'not_approved' => 'Not Approved',
                        'sanctioned' => 'Sanctioned',
                    ]),

                SelectFilter::make('employee_id')
                    ->label('Case Owner')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),
            ])
            ->recordActions([
                Action::make('takeOver')
                    ->label('Take Over')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->url(fn (): string => JourneyTakeoverResource::getUrl('index')),
            ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function slaState(Customer $customer): array
    {
        $module = JourneyModule::forCustomer($customer);
        $minutesInStage = JourneySlaService::stageEnteredAt($customer)->diffInMinutes(now());

        $reminderThreshold = (int) (config("journey_sla.reminder_minutes.{$module->value}") ?? 60);
        $escalationThreshold = (int) (config("journey_sla.escalation_minutes.{$module->value}") ?? 120);

        if ($minutesInStage >= $escalationThreshold) {
            return ['Breached', 'danger'];
        }

        if ($minutesInStage >= $reminderThreshold) {
            return ['At Risk', 'warning'];
        }

        return ['On Track', 'success'];
    }
}
