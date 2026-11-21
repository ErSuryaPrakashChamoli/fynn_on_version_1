<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CashbackType;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestForm;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerCashbackService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The case's cashback and its cashback forms on the customer page: fill a
 * new form (also for a case punched in on an earlier day or month), and
 * approve / reject the one waiting at the user's stage. A case can carry
 * several forms, so retrieving, changing an amount, recording a payment and
 * requesting / approving a returned cashback happen on each form's voucher. Every write goes through
 * CustomerCashbackService.
 */
class CustomerCashbackSection
{
    public static function make(): Section
    {
        $pending = fn (?Customer $record) => $record ? app(CustomerCashbackService::class)->pendingRequest($record) : null;
        $refresh = fn (mixed $record): mixed => $record instanceof Customer ? $record->refresh() : null;

        return Section::make('Cashback')
            ->key('customerCashback')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->description(fn (?Customer $record): string => match (true) {
                $record === null => '',
                app(CustomerCashbackService::class)->hasLiveForms($record) => CashbackType::labelFor($record->cashback_option).' — ₹'.indianNumberFormat($record->cashback ?? 0).' on this case, from its cashback forms'
                    .((float) ($record->cashback_before_forms ?? 0) > 0 ? ' (incl. ₹'.indianNumberFormat($record->cashback_before_forms).' entered before forms).' : '.'),
                filled($record->cashback_option) => 'Declared: '.CashbackType::labelFor($record->cashback_option).'.',
                default => 'Cashback forms on this case.',
            })
            ->columnSpanFull()
            ->collapsible()
            ->visible(fn (?Customer $record): bool => $record !== null
                && (in_array(strtolower((string) $record->journey_status), CustomerCashbackService::OPEN_JOURNEY_STATUSES, true)
                    || $record->cashbackRequests()->exists()))
            ->headerActions([
                self::fillAction(),
                CustomerCashbackRequestActions::approve($pending, $refresh),
                CustomerCashbackRequestActions::reject($pending, $refresh),
            ])
            ->schema([
                View::make('filament.components.customer-cashback-forms')
                    ->viewData(fn (?Customer $record): array => [
                        'requests' => $record
                            ? $record->cashbackRequests()->with(['requester', 'supervisor', 'managerApprover', 'approver'])->latest('id')->get()
                            : collect(),
                        'cashbackBeforeForms' => (float) ($record?->cashback_before_forms ?? 0),
                    ]),
            ]);
    }

    /**
     * Fill a cashback form from the customer page (Team Leader and above,
     * or the Admin). The declaration is chosen in the form itself; a new
     * form adds to the cashback the case already carries.
     */
    public static function fillAction(string $name = 'fillCashbackForm', ?CashbackType $fixedType = null): Action
    {
        return Action::make($name)
            ->label('Fill cashback form')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('primary')
            ->visible(fn (?Customer $record): bool => $record !== null
                && app(CustomerCashbackService::class)->canRaiseOn(self::user(), $record))
            ->modalHeading('Cashback form')
            ->modalDescription(fn (?Customer $record): string => trim(($record?->customer_name ?? '').' · PAN '.($record?->pan_number ?? '—').' · '.($record?->mobile_no ?? '')).'. The customer\'s details come from the file.'
                .((float) ($record?->cashback ?? 0) > 0 ? ' This case already carries ₹'.indianNumberFormat($record->cashback).' cashback — this form adds to it.' : ''))
            ->modalSubmitActionLabel('Submit for approval')
            ->schema(CustomerCashbackRequestForm::fields($fixedType))
            ->action(function (array $data, Customer $record, Component $livewire) use ($fixedType): void {
                $user = self::user();

                if (! $user instanceof User) {
                    return;
                }

                if ($fixedType !== null) {
                    $data['type'] = $fixedType->value;
                }

                try {
                    $form = app(CustomerCashbackService::class)->raise($user, $record, $data);
                } catch (AuthorizationException|ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                self::refresh($livewire);

                Notification::make()
                    ->success()
                    ->title('Cashback form '.$form->voucher_no.' submitted')
                    ->body(match (true) {
                        $form->isApproved() => 'Approved — Accounts can now pay it.',
                        $form->supervisor_due_at !== null => 'Waiting for '.($form->supervisor?->emp_name ?? 'the supervisor').' — 24 hours to approve, then it moves to the Admin.',
                        default => 'It is now waiting for the Admin\'s approval.',
                    })
                    ->actions([
                        Action::make('open')->label('Open voucher')->url(CustomerCashbackRequestResource::getUrl('view', ['record' => $form])),
                    ])
                    ->send();
            });
    }

    /**
     * Pulls the file's new cashback figures into an open edit form so a
     * later Save cannot write stale ones back.
     */
    public static function refresh(Component $livewire): void
    {
        if (method_exists($livewire, 'getRecord')) {
            $livewire->getRecord()?->refresh();
        }

        if (method_exists($livewire, 'refreshFormData')) {
            $livewire->refreshFormData(['cashback', 'cashback_option']);
        }
    }

    private static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
