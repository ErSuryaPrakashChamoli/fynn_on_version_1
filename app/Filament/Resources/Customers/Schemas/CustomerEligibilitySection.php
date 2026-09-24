<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * Eligibility status, its actions and its log — shared by the customer edit
 * form and the view infolist, because callers only ever get the view page.
 * All writes go through CustomerEligibilityService.
 */
class CustomerEligibilitySection
{
    /** Form fields whose values an eligibility action can change. */
    private const REFRESHED_FIELDS = ['eligibility_status', 'eligibility_reason', 'journey_status'];

    public static function make(): Section
    {
        return Section::make('Eligibility')
            ->key('customerEligibility')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->description(fn (?Customer $record): string => match ($record?->eligibility_status) {
                CustomerEligibilityService::ELIGIBLE => 'Eligible — locked. The file carries on to SFL.',
                CustomerEligibilityService::NOT_ELIGIBLE => 'Not Eligible — only the Admin can make it eligible, on request.',
                CustomerEligibilityService::CONSENT_PENDING => 'Consent Pending — can be changed any time. Every change is logged for the Admin and seniors.',
                default => 'Eligibility log for this file.',
            })
            ->columnSpanFull()
            ->collapsible()
            ->visible(fn (?Customer $record): bool => $record !== null)
            ->headerActions([
                self::changeAction(),
                self::requestAction(),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->schema([
                View::make('filament.components.customer-eligibility-log')
                    ->viewData(fn (?Customer $record): array => [
                        'status' => $record?->eligibility_status,
                        'pendingRequest' => $record ? app(CustomerEligibilityService::class)->pendingRequest($record) : null,
                        'logs' => $record ? app(CustomerEligibilityService::class)->logs($record) : collect(),
                    ]),
            ]);
    }

    private static function changeAction(): Action
    {
        return Action::make('changeEligibility')
            ->label('Change Eligibility')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->visible(fn (?Customer $record): bool => $record !== null
                && app(CustomerEligibilityService::class)->canChangeStatus(self::user(), $record))
            ->modalHeading('Change eligibility')
            ->modalDescription('Marking the file Eligible locks eligibility for good and moves it to SFL. Marking it Not Eligible means only the Admin can make it eligible later.')
            ->modalSubmitActionLabel('Save')
            ->schema([
                Select::make('eligibility_status')
                    ->label('New status')
                    ->options([
                        CustomerEligibilityService::ELIGIBLE => CustomerEligibilityService::STATUS_LABELS[CustomerEligibilityService::ELIGIBLE],
                        CustomerEligibilityService::NOT_ELIGIBLE => CustomerEligibilityService::STATUS_LABELS[CustomerEligibilityService::NOT_ELIGIBLE],
                    ])
                    ->searchable(false)
                    ->live()
                    ->required(),

                Select::make('eligibility_reason')
                    ->label('Not Eligible Reason')
                    ->options(CustomerEligibilityService::NOT_ELIGIBLE_REASONS)
                    ->visible(fn (Get $get): bool => $get('eligibility_status') === CustomerEligibilityService::NOT_ELIGIBLE)
                    ->required(fn (Get $get): bool => $get('eligibility_status') === CustomerEligibilityService::NOT_ELIGIBLE),

                Textarea::make('remarks')
                    ->label('Remarks')
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, Customer $record, Component $livewire): void {
                self::run(
                    fn (User $user) => app(CustomerEligibilityService::class)->changeStatus(
                        $user,
                        $record,
                        $data['eligibility_status'],
                        $data['eligibility_reason'] ?? null,
                        $data['remarks'] ?? null,
                    ),
                    'Eligibility updated',
                    $livewire,
                );
            });
    }

    private static function requestAction(): Action
    {
        return Action::make('requestEligibility')
            ->label('Request Eligibility')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn (?Customer $record): bool => $record !== null
                && app(CustomerEligibilityService::class)->canRaiseRequest(self::user(), $record))
            ->modalHeading('Ask the Admin to make this file eligible')
            ->modalSubmitActionLabel('Send request')
            ->schema([
                Textarea::make('reason')
                    ->label('Why should it be eligible?')
                    ->rows(4)
                    ->maxLength(1000)
                    ->required(),
            ])
            ->action(function (array $data, Customer $record, Component $livewire): void {
                self::run(
                    fn (User $user) => app(CustomerEligibilityService::class)->raiseRequest($user, $record, $data['reason']),
                    'Request sent to the Admin',
                    $livewire,
                );
            });
    }

    private static function approveAction(): Action
    {
        return Action::make('approveEligibilityRequest')
            ->label('Approve Request')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (?Customer $record): bool => $record !== null
                && CustomerEligibilityService::isReviewer(self::user())
                && app(CustomerEligibilityService::class)->pendingRequest($record) !== null)
            ->modalHeading('Approve eligibility request')
            ->modalDescription('The file becomes Eligible, moves to SFL, and its eligibility is locked.')
            ->modalSubmitActionLabel('Approve')
            ->schema([
                Textarea::make('review_note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, Customer $record, Component $livewire): void {
                self::run(
                    fn (User $user) => app(CustomerEligibilityService::class)->approve(
                        $user,
                        app(CustomerEligibilityService::class)->pendingRequest($record) ?? throw new AuthorizationException('There is no pending request on this file.'),
                        $data['review_note'] ?? null,
                    ),
                    'Request approved — file is now Eligible',
                    $livewire,
                );
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('rejectEligibilityRequest')
            ->label('Reject Request')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (?Customer $record): bool => $record !== null
                && CustomerEligibilityService::isReviewer(self::user())
                && app(CustomerEligibilityService::class)->pendingRequest($record) !== null)
            ->modalHeading('Reject eligibility request')
            ->modalSubmitActionLabel('Reject')
            ->schema([
                Textarea::make('review_note')
                    ->label('Why is it rejected?')
                    ->rows(2)
                    ->maxLength(1000)
                    ->required(),
            ])
            ->action(function (array $data, Customer $record, Component $livewire): void {
                self::run(
                    fn (User $user) => app(CustomerEligibilityService::class)->reject(
                        $user,
                        app(CustomerEligibilityService::class)->pendingRequest($record) ?? throw new AuthorizationException('There is no pending request on this file.'),
                        $data['review_note'],
                    ),
                    'Request rejected',
                    $livewire,
                );
            });
    }

    /**
     * Runs a service call, reports a refusal instead of throwing, and pulls
     * the new values into the page's form so a later Save cannot write the
     * stale ones back.
     *
     * @param  callable(User): mixed  $callback
     */
    private static function run(callable $callback, string $successTitle, Component $livewire): void
    {
        $user = self::user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $callback($user);
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (method_exists($livewire, 'getRecord')) {
            $livewire->getRecord()?->refresh();
        }

        if (method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(self::REFRESHED_FIELDS);
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    private static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
