<?php

namespace App\Filament\Resources\JourneyTakeovers\Tables;

use App\Models\Customer;
use App\Models\JourneyTakeover;
use App\Services\Journey\JourneyTakeoverService;
use App\Support\EmployeeOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class JourneyTakeoversTable
{
    private const TYPE_LABELS = [
        'manager_unavailable' => 'Manager Unavailable',
        'emergency' => 'Emergency',
        'sla_breach' => 'SLA Breach',
        'manager_on_leave' => 'Manager on Leave',
        'manager_resigned' => 'Manager Resigned',
        'manager_terminated' => 'Manager Terminated',
        'escalation' => 'Escalation',
        'other' => 'Other',
    ];

    /**
     * @return array<int, string>
     */
    private static function customerOptions(?string $search = null): array
    {
        return Customer::query()
            ->when(
                filled($search),
                fn ($query) => $query->where(
                    fn ($q) => $q->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('application_no', 'like', "%{$search}%")
                        ->orWhere('lan_no', 'like', "%{$search}%")
                ),
            )
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'customer_name', 'application_no'])
            ->mapWithKeys(fn (Customer $customer): array => [
                $customer->id => "{$customer->customer_name} ({$customer->application_no})",
            ])
            ->all();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('originalManager.emp_name')
                    ->label('Original Manager')
                    ->description(fn (JourneyTakeover $record): ?string => $record->originalManager?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('takeoverBy.emp_name')
                    ->label('Taken Over By')
                    ->description(fn (JourneyTakeover $record): ?string => $record->takeoverBy?->emp_id)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('takeover_type')
                    ->label('Reason Type')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => self::TYPE_LABELS[$state] ?? (string) $state),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'success' => JourneyTakeover::STATUS_ACTIVE,
                        'gray' => JourneyTakeover::STATUS_ENDED,
                        'warning' => JourneyTakeover::STATUS_CANCELLED,
                    ]),

                TextColumn::make('started_at')
                    ->dateTime('d M Y h:i A')
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        JourneyTakeover::STATUS_ACTIVE => 'Active',
                        JourneyTakeover::STATUS_ENDED => 'Ended',
                        JourneyTakeover::STATUS_CANCELLED => 'Cancelled',
                    ]),

                SelectFilter::make('takeover_type')
                    ->label('Reason Type')
                    ->multiple()
                    ->options(self::TYPE_LABELS),

                SelectFilter::make('takeover_by_id')
                    ->label('Taken Over By')
                    ->multiple()
                    ->options(fn (): array => EmployeeOptions::visibleTo()),
            ])
            ->recordActions([
                Action::make('end')
                    ->label('End Takeover')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (JourneyTakeover $record): bool => $record->status === JourneyTakeover::STATUS_ACTIVE)
                    ->action(function (JourneyTakeover $record): void {
                        try {
                            app(JourneyTakeoverService::class)->end($record, (int) auth()->id());

                            Notification::make()->success()->title('Takeover ended')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not end takeover')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                Action::make('takeOverJourney')
                    ->label('Take Over Journey')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->modalHeading('Take Over Customer Journey')
                    ->modalDescription('Grants you access to this customer\'s current Manager-stage actions only. Ownership is not transferred — use Permanent Reassignment for that.')
                    ->modalWidth('xl')
                    ->form([
                        Select::make('customer_id')
                            ->label('Customer')
                            // Typing searches every customer; without a search
                            // term we still show the newest few so the field is
                            // never an empty dropdown.
                            ->options(fn (): array => self::customerOptions())
                            ->getSearchResultsUsing(fn (string $search): array => self::customerOptions($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                $customer = Customer::find($value);

                                return $customer
                                    ? "{$customer->customer_name} ({$customer->application_no})"
                                    : null;
                            })
                            ->searchable()
                            ->required(),

                        Select::make('takeover_type')
                            ->label('Reason')
                            ->options(self::TYPE_LABELS)
                            ->live()
                            ->required(),

                        Textarea::make('reason')
                            ->label(fn (Get $get): string => $get('takeover_type') === 'other' ? 'Please specify the reason' : 'Additional detail')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $employee = auth()->user()->employee;

                        if (! $employee) {
                            Notification::make()->danger()->title('No employee profile linked to your account.')->send();

                            return;
                        }

                        try {
                            app(JourneyTakeoverService::class)->takeOver($data, $employee->id, (int) auth()->id());

                            Notification::make()->success()->title('Journey taken over')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not take over journey')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->persistent()
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Could not take over journey')
                                ->body('An unexpected error occurred. No takeover was recorded.')
                                ->send();
                        }
                    }),
            ]);
    }
}
