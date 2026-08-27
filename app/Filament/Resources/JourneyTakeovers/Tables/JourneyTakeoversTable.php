<?php

namespace App\Filament\Resources\JourneyTakeovers\Tables;

use App\Models\Customer;
use App\Models\JourneyTakeover;
use App\Services\Journey\JourneyTakeoverService;
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

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('customer.application_no')
                    ->label('Application No'),

                TextColumn::make('originalManager.emp_name')
                    ->label('Original Manager'),

                TextColumn::make('takeoverBy.emp_name')
                    ->label('Taken Over By'),

                TextColumn::make('takeover_type')
                    ->label('Reason Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::TYPE_LABELS[$state] ?? (string) $state),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => JourneyTakeover::STATUS_ACTIVE,
                        'gray' => JourneyTakeover::STATUS_ENDED,
                        'warning' => JourneyTakeover::STATUS_CANCELLED,
                    ]),

                TextColumn::make('started_at')
                    ->dateTime('d M Y h:i A'),

                TextColumn::make('ended_at')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        JourneyTakeover::STATUS_ACTIVE => 'Active',
                        JourneyTakeover::STATUS_ENDED => 'Ended',
                        JourneyTakeover::STATUS_CANCELLED => 'Cancelled',
                    ]),
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
                            ->options(fn (): array => Customer::query()
                                ->orderByDesc('created_at')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (Customer $customer): array => [
                                    $customer->id => "{$customer->customer_name} ({$customer->application_no})",
                                ])
                                ->all())
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
