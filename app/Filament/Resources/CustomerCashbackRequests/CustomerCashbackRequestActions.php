<?php

namespace App\Filament\Resources\CustomerCashbackRequests;

use App\Enums\CashbackRequestStatus;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestForm;
use App\Filament\Resources\CustomerCashbackRequests\Schemas\CustomerCashbackRequestInfolist;
use App\Models\CustomerCashbackRequest;
use App\Models\User;
use App\Services\CustomerCashbackService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Approve / reject / retrieve / change amount / record payment / request a
 * return (and the Admin's approval of it) on a cashback form, shared by the listing rows, the voucher page and the
 * customer page. Each action re-checks the user inside
 * CustomerCashbackService; visibility only hides what would be refused.
 *
 * $resolve maps the action's record to the form (the record is the form
 * itself on the cashback screens, the customer on the customer page).
 */
class CustomerCashbackRequestActions
{
    /**
     * @return list<Action>
     */
    public static function all(?Closure $resolve = null, ?Closure $after = null): array
    {
        return [
            self::approve($resolve, $after),
            self::reject($resolve, $after),
            self::retrieve($resolve, $after),
            self::changeAmount($resolve, $after),
            self::updatePayment($resolve, $after),
            self::revise($resolve),
            self::requestReturn($resolve, $after),
            self::approveReturn($resolve, $after),
            self::rejectReturn($resolve, $after),
        ];
    }

    public static function approve(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('approveCashback')
            ->label(fn ($record): string => self::isAdmin() ? 'Approve' : 'Approve & send to Admin')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn ($record): string => 'Approve cashback form '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(fn ($record): string => self::isAdmin()
                ? 'Final approval. The file\'s cashback is set to the approved amount and Accounts are told it is ready to pay.'
                : 'Your approval sends the form to the Admin for final approval.')
            ->modalContent(fn ($record): ?View => self::analysis($record, $resolve))
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn ($record): array => ['approved_amount' => self::form($record, $resolve)?->amount])
            ->schema(fn (): array => array_values(array_filter([
                self::isAdmin()
                    ? TextInput::make('approved_amount')
                        ->label('Approved amount')
                        ->indianAmount(min: 1)
                        ->required()
                        ->helperText('Change it only if the approved cashback differs from what was asked for.')
                    : null,
                Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(1000),
            ])))
            ->visible(fn ($record): bool => self::can('canApprove', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->approve($form, $user, $data['note'] ?? null, $data['approved_amount'] ?? null),
                    $record,
                    $resolve,
                    $after,
                    fn (CustomerCashbackRequest $form): string => $form->status === CashbackRequestStatus::Approved ? 'Cashback approved' : 'Approved — sent to the Admin',
                );
            });
    }

    public static function reject(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('rejectCashback')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject this cashback form')
            ->modalDescription('The file\'s cashback goes back to 0 and the person who filled the form is told why. A new form can be filled after.')
            ->modalContent(fn ($record): ?View => self::analysis($record, $resolve))
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                Textarea::make('rejection_reason')->label('Why is it rejected?')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn ($record): bool => self::can('canReject', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->reject($form, $user, (string) $data['rejection_reason']),
                    $record,
                    $resolve,
                    $after,
                    'Cashback form rejected',
                );
            });
    }

    public static function retrieve(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('retrieveCashback')
            ->label('Retrieve expected cashback')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Retrieve the expected cashback')
            ->modalDescription(fn ($record): string => 'The expected cashback of ₹'.indianNumberFormat(self::form($record, $resolve)?->effectiveAmount()).' is not needed any more. The file\'s cashback goes back to 0 and the achievement figures recover it. This cannot be undone — a new form would be needed.')
            ->schema([
                Textarea::make('retrieval_reason')->label('Why is it not needed?')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn ($record): bool => self::can('canRetrieve', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->retrieve($form, $user, (string) $data['retrieval_reason']),
                    $record,
                    $resolve,
                    $after,
                    'Expected cashback retrieved',
                );
            });
    }

    public static function changeAmount(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('changeCashbackAmount')
            ->label('Change amount')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Change the approved cashback amount')
            ->modalDescription('Only the Admin can change an approved amount. The file\'s cashback — and so the achievement and incentive figures — follow it, and the change is logged with your reason.')
            ->fillForm(fn ($record): array => ['approved_amount' => self::form($record, $resolve)?->effectiveAmount()])
            ->schema([
                TextInput::make('approved_amount')->label('New amount')->indianAmount(min: 0)->required(),
                Textarea::make('reason')->label('Why is it changing?')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn ($record): bool => self::can('canChangeAmount', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->changeAmount($form, $user, $data['approved_amount'] ?? null, (string) ($data['reason'] ?? '')),
                    $record,
                    $resolve,
                    $after,
                    'Cashback amount changed',
                );
            });
    }

    public static function updatePayment(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('updateCashbackPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->modalHeading(fn ($record): string => 'Payment of '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(fn ($record): string => '₹'.indianNumberFormat(self::form($record, $resolve)?->effectiveAmount()).' to '.self::form($record, $resolve)?->customer?->customer_name.'. Only the Admin and Accounts can update it.')
            ->fillForm(function ($record) use ($resolve): array {
                $form = self::form($record, $resolve);

                return [
                    'payment_status' => $form?->payment_status->value,
                    'payment_mode' => $form?->payment_mode?->value,
                    'upi_platform' => $form?->upi_platform,
                    'payee_upi' => $form?->payee_upi,
                    'payee_account_no' => $form?->payee_account_no,
                    'payee_ifsc' => $form?->payee_ifsc,
                    'transaction_id' => $form?->transaction_id,
                    'payment_date' => $form?->payment_date?->toDateString(),
                    'paid_by_name' => $form?->paid_by_name ?? self::user()?->name,
                    'payment_remarks' => $form?->payment_remarks,
                ];
            })
            ->schema(CustomerCashbackRequestForm::paymentFields())
            ->visible(fn ($record): bool => self::can('canUpdatePayment', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->updatePayment($form, $user, $data),
                    $record,
                    $resolve,
                    $after,
                    'Payment details saved',
                );
            });
    }

    /**
     * A second payment on a paid cashback: opens a revision for the extra
     * amount, which goes through the same approval and is paid with its
     * own UTR.
     */
    public static function revise(?Closure $resolve = null): Action
    {
        return Action::make('reviseCashback')
            ->label('Revise cashback')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('warning')
            ->modalHeading(fn ($record): string => 'Revise cashback '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(function ($record) use ($resolve): string {
                $form = self::form($record, $resolve);

                return sprintf(
                    '₹%s was paid%s. Fill the extra amount to be paid now — the revision goes through the same approval (supervisor within 24 hours, then the Admin) and is paid with its own UTR. The case\'s cashback becomes the total of both.',
                    indianNumberFormat($form?->effectiveAmount()),
                    filled($form?->transaction_id) ? ' (UTR '.$form->transaction_id.')' : '',
                );
            })
            ->modalSubmitActionLabel('Submit revision for approval')
            ->fillForm(function ($record) use ($resolve): array {
                $form = self::form($record, $resolve);

                return [
                    'subvention_amount' => $form?->customer?->subvention,
                    'payment_mode' => $form?->payment_mode?->value,
                    'upi_platform' => $form?->upi_platform,
                    'payee_upi' => $form?->payee_upi,
                    'payee_account_no' => $form?->payee_account_no,
                    'payee_ifsc' => $form?->payee_ifsc,
                ];
            })
            ->schema(fn ($record): array => CustomerCashbackRequestForm::revisionFields(self::form($record, $resolve)?->type))
            ->visible(fn ($record): bool => self::can('canRevise', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve): void {
                $user = self::user();
                $original = self::form($record, $resolve);

                if (! $user instanceof User || ! $original instanceof CustomerCashbackRequest) {
                    return;
                }

                try {
                    $revision = app(CustomerCashbackService::class)->revise($user, $original, $data);
                } catch (AuthorizationException|ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Revision '.$revision->voucher_no.' submitted for approval')
                    ->actions([
                        Action::make('open')->label('Open revision')->url(CustomerCashbackRequestResource::getUrl('view', ['record' => $revision])),
                    ])
                    ->send();
            });
    }

    /**
     * The customer gave a paid cashback back (in full or in part): the
     * case's Team Leader or Manager asks for it to be recorded with the
     * amount, how and when it came back and why. It counts only once the
     * Admin approves it (approveReturn()).
     */
    public static function requestReturn(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('requestCashbackReturn')
            ->label('Request cashback return')
            ->icon(Heroicon::OutlinedArrowUturnDown)
            ->color('danger')
            ->modalHeading(fn ($record): string => 'Cashback returned on '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(function ($record) use ($resolve): string {
                $form = self::form($record, $resolve);

                return sprintf(
                    '₹%s was paid to %s%s%s. Fill in what the customer paid back. The Admin approves it — only then does the case\'s cashback drop by that amount and the achievement figures recover it.',
                    indianNumberFormat($form?->effectiveAmount()),
                    $form?->customer?->customer_name ?? 'the customer',
                    $form?->payment_date ? ' on '.$form->payment_date->format('d M Y') : '',
                    filled($form?->transaction_id) ? ' (UTR '.$form->transaction_id.')' : '',
                );
            })
            ->modalSubmitActionLabel('Send to Admin for approval')
            ->fillForm(fn ($record): array => [
                'returned_amount' => self::form($record, $resolve)?->effectiveAmount(),
                'return_date' => today()->toDateString(),
            ])
            ->schema(fn ($record): array => CustomerCashbackRequestForm::returnFields(self::form($record, $resolve)?->effectiveAmount()))
            ->visible(fn ($record): bool => self::can('canRequestReturn', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->requestReturn($form, $user, $data),
                    $record,
                    $resolve,
                    $after,
                    'Cashback return sent to the Admin for approval',
                );
            });
    }

    public static function approveReturn(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('approveCashbackReturn')
            ->label('Approve cashback return')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn ($record): string => 'Approve the cashback return on '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(fn ($record): string => self::returnSummary(self::form($record, $resolve)).' Once approved, the case\'s cashback drops by this amount.')
            ->schema([
                Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(1000),
            ])
            ->visible(fn ($record): bool => self::can('canReviewReturn', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->approveReturn($form, $user, $data['note'] ?? null),
                    $record,
                    $resolve,
                    $after,
                    fn (CustomerCashbackRequest $form): string => 'Return of ₹'.indianNumberFormat($form->returnedAmount()).' approved',
                );
            });
    }

    public static function rejectReturn(?Closure $resolve = null, ?Closure $after = null): Action
    {
        return Action::make('rejectCashbackReturn')
            ->label('Reject cashback return')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn ($record): string => 'Reject the cashback return on '.self::form($record, $resolve)?->voucher_no)
            ->modalDescription(fn ($record): string => self::returnSummary(self::form($record, $resolve)).' The case\'s cashback stays as it is; the return can be asked for again.')
            ->schema([
                Textarea::make('return_review_note')->label('Why is it rejected?')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn ($record): bool => self::can('canReviewReturn', $record, $resolve))
            ->action(function ($record, array $data) use ($resolve, $after): void {
                self::run(
                    fn (User $user, CustomerCashbackRequest $form) => app(CustomerCashbackService::class)->rejectReturn($form, $user, (string) $data['return_review_note']),
                    $record,
                    $resolve,
                    $after,
                    'Cashback return rejected',
                );
            });
    }

    /** "Asha asked to record ₹2,000 returned on 21 Nov 2026 by UPI (UTR …): reason." */
    private static function returnSummary(?CustomerCashbackRequest $form): string
    {
        if ($form === null) {
            return '';
        }

        return sprintf(
            '%s asked to record ₹%s of ₹%s returned on %s by %s%s: %s.',
            $form->returnRequester?->name ?? 'Someone',
            indianNumberFormat($form->returned_amount),
            indianNumberFormat($form->effectiveAmount()),
            $form->return_date?->format('d M Y') ?? '—',
            $form->return_mode?->label() ?? '—',
            filled($form->return_reference) ? ' (UTR '.$form->return_reference.')' : '',
            rtrim((string) $form->return_reason, '.'),
        );
    }

    /**
     * The cashback analysis of everyone on the form's chain as it stands
     * now, shown in the approve / reject pop-ups so the decision is taken
     * on it.
     */
    private static function analysis(mixed $record, ?Closure $resolve): ?View
    {
        $form = self::form($record, $resolve);

        return $form
            ? view('filament.components.cashback-form-analysis', CustomerCashbackRequestInfolist::analysisViewData($form, withSaved: false))
            : null;
    }

    private static function form(mixed $record, ?Closure $resolve): ?CustomerCashbackRequest
    {
        $form = $resolve ? $resolve($record) : $record;

        return $form instanceof CustomerCashbackRequest ? $form : null;
    }

    private static function can(string $check, mixed $record, ?Closure $resolve): bool
    {
        $form = self::form($record, $resolve);

        return $form !== null && app(CustomerCashbackService::class)->{$check}(self::user(), $form);
    }

    private static function isAdmin(): bool
    {
        return CustomerCashbackService::isAdmin(self::user());
    }

    private static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Runs a service call and reports a refusal or validation error as a
     * notification instead of throwing.
     *
     * @param  Closure(User, CustomerCashbackRequest): mixed  $callback
     * @param  string|Closure(CustomerCashbackRequest): string  $success
     */
    private static function run(Closure $callback, mixed $record, ?Closure $resolve, ?Closure $after, string|Closure $success): void
    {
        $user = self::user();
        $form = self::form($record, $resolve);

        if (! $user instanceof User || ! $form instanceof CustomerCashbackRequest) {
            return;
        }

        try {
            $callback($user, $form);
        } catch (AuthorizationException|ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if ($after) {
            $after($record);
        }

        Notification::make()->title($success instanceof Closure ? $success($form) : $success)->success()->send();
    }
}
