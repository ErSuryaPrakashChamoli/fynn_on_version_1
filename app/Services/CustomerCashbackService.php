<?php

namespace App\Services;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use App\Enums\NotificationCategory;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Models\Customer;
use App\Models\CustomerCashbackRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single write path for cashback forms and for customers.cashback on
 * files declared for cashback (the digital "Acknowledgment" voucher).
 *
 *  - A Team Leader or anyone above them on the file's chain fills the form;
 *    a Team Leader's waits for the Manager, then the Admin. A Manager's (or
 *    Cluster Manager's / Business Head's) goes straight to the Admin; the
 *    Admin's own is approved as it is filled.
 *  - A file holds at most one live (pending or approved) form, and
 *    customers.cashback always equals that form's amount (0 when none is
 *    live) — so the achievement and incentive maths, which already read
 *    customers.cashback, follow the form, its approval, a retrieval or the
 *    Admin's change without any change of their own.
 *  - After approval only the Admin changes the amount; the Admin or
 *    Accounts record the payment (mode, UTR, date, Paid / Not Paid / On Hold).
 *  - An unpaid expected cashback can be retrieved by the Team Leader or
 *    Manager when it is not needed any more; the file's cashback drops to 0.
 */
class CustomerCashbackService
{
    /** Who may fill a cashback form (besides the Admin). */
    public const RAISER_ROLES = ['Team Leader', 'Manager', 'Cluster Manager', 'Business Head'];

    /** Who signs a Team Leader's form off before it reaches the Admin. */
    public const MANAGER_ROLES = ['Manager', 'Cluster Manager', 'Business Head'];

    /** Who records the payment against an approved form. */
    public const PAYMENT_ROLES = ['Admin', 'Accounts'];

    /** Journey stages a form can be filled at: credit approved (Step 4 open) or disbursed. */
    public const OPEN_JOURNEY_STATUSES = ['approved', 'sanctioned'];

    public function __construct(private CustomerEligibilityService $eligibility) {}

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && $user->hasRole('Admin');
    }

    public static function isPaymentUser(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::PAYMENT_ROLES);
    }

    public function liveRequest(Customer $customer): ?CustomerCashbackRequest
    {
        return $customer->cashbackRequests()
            ->live()
            ->latest('id')
            ->first();
    }

    /**
     * The owner's chain (Team Leader and up) or a continuity stand-in, or
     * the Admin — on a file at Step 4 or later with no live form.
     */
    public function canRaiseOn(?User $user, Customer $customer): bool
    {
        return $this->mayFillFor($user, $customer)
            && in_array(strtolower((string) $customer->journey_status), self::OPEN_JOURNEY_STATUSES, true)
            && $this->liveRequest($customer) === null;
    }

    public function canApprove(?User $user, CustomerCashbackRequest $request): bool
    {
        if ($user === null || ! $request->isPending()) {
            return false;
        }

        if (self::isAdmin($user)) {
            return true;
        }

        return $request->status === CashbackRequestStatus::PendingManager
            && $this->signsForManager($user, $request);
    }

    public function canReject(?User $user, CustomerCashbackRequest $request): bool
    {
        return $this->canApprove($user, $request);
    }

    public function canRetrieve(?User $user, CustomerCashbackRequest $request): bool
    {
        return $request->type === CashbackType::Expected
            && $request->isLive()
            && ! $request->isPaid()
            && $request->customer !== null
            && $this->mayFillFor($user, $request->customer);
    }

    public function canChangeAmount(?User $user, CustomerCashbackRequest $request): bool
    {
        return self::isAdmin($user) && $request->isApproved();
    }

    public function canUpdatePayment(?User $user, CustomerCashbackRequest $request): bool
    {
        return self::isPaymentUser($user) && $request->isApproved();
    }

    /**
     * @param  array{type?: string|null, amount?: mixed, remarks?: string|null, payment_mode?: string|null, upi_platform?: string|null, payee_upi?: string|null, payee_account_no?: string|null, payee_ifsc?: string|null}  $data
     */
    public function raise(User $user, Customer $customer, array $data): CustomerCashbackRequest
    {
        if (! $this->mayFillFor($user, $customer)) {
            throw new AuthorizationException('Only the Team Leader or Manager of this file (or the Admin) can fill its cashback form.');
        }

        if (! in_array(strtolower((string) $customer->journey_status), self::OPEN_JOURNEY_STATUSES, true)) {
            throw ValidationException::withMessages(['type' => 'A cashback form can be filled once the file reaches the disbursal step.']);
        }

        $type = CashbackType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null || ! $type->needsForm()) {
            throw ValidationException::withMessages(['type' => 'Choose Cashback or Expected cashback.']);
        }

        $amount = $this->validAmount($data['amount'] ?? null, 'amount', allowZero: false);
        $payee = $this->validPayee($data, required: $type === CashbackType::Cashback);

        $request = DB::transaction(function () use ($user, $customer, $type, $amount, $payee, $data): CustomerCashbackRequest {
            /** @var Customer $customer */
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());

            if ($this->liveRequest($customer) !== null) {
                throw ValidationException::withMessages(['type' => 'This file already has a cashback form in progress or approved.']);
            }

            $now = now();
            $owner = $customer->assign_to ? Employee::query()->find($customer->assign_to) : null;
            $status = match (true) {
                self::isAdmin($user) => CashbackRequestStatus::Approved,
                $user->hasAnyRole(self::MANAGER_ROLES) => CashbackRequestStatus::PendingAdmin,
                default => CashbackRequestStatus::PendingManager,
            };

            $request = CustomerCashbackRequest::query()->create([
                'customer_id' => $customer->getKey(),
                'type' => $type,
                'amount' => $amount,
                'approved_amount' => $status === CashbackRequestStatus::Approved ? $amount : null,
                'status' => $status,
                'remarks' => filled($data['remarks'] ?? null) ? trim((string) $data['remarks']) : null,
                ...$payee,
                'aro_employee_id' => $owner?->getKey(),
                'team_leader_employee_id' => $this->chainMember($owner, Employee::DESIGNATION_TEAM_LEADER)?->getKey(),
                'manager_employee_id' => $this->chainMember($owner, Employee::DESIGNATION_MANAGER)?->getKey(),
                'requested_by' => $user->getKey(),
                'manager_approved_by' => $status !== CashbackRequestStatus::PendingManager && ! self::isAdmin($user) ? $user->getKey() : null,
                'manager_approved_at' => $status !== CashbackRequestStatus::PendingManager && ! self::isAdmin($user) ? $now : null,
                'approved_by' => $status === CashbackRequestStatus::Approved ? $user->getKey() : null,
                'approved_at' => $status === CashbackRequestStatus::Approved ? $now : null,
                'payment_status' => CashbackPaymentStatus::NotPaid,
            ]);

            $request->forceFill(['voucher_no' => self::voucherNumber($request->getKey())])->saveQuietly();

            $this->syncCustomer($customer);

            return $request;
        });

        $summary = sprintf('%s filled a %s form for ₹%s.', $user->name, strtolower($type->label()), indianNumberFormat($amount));

        match ($request->status) {
            CashbackRequestStatus::PendingManager => $this->notify($this->managerStageUsers($request), 'Cashback form to approve: '.$customer->customer_name, $summary, 'warning', $request),
            CashbackRequestStatus::PendingAdmin => $this->notify($this->admins(), 'Cashback form to approve: '.$customer->customer_name, $summary, 'warning', $request),
            default => $this->notify($this->paymentUsers()->reject(fn (User $recipient): bool => $recipient->is($user)), 'Cashback approved: '.$customer->customer_name, $summary.' Approved by the Admin — ready to pay.', 'success', $request),
        };

        return $request;
    }

    /**
     * Manager sign-off moves a Team Leader's form on to the Admin; the
     * Admin's approval (at either stage) is final and may set the amount.
     */
    public function approve(CustomerCashbackRequest $request, User $user, ?string $note = null, mixed $amount = null): CustomerCashbackRequest
    {
        if (! $this->canApprove($user, $request)) {
            throw new AuthorizationException($request->isPending()
                ? 'This cashback form is waiting for someone else to approve it.'
                : 'This cashback form has already been '.strtolower($request->status->label()).'.');
        }

        $note = filled($note) ? trim((string) $note) : null;

        if (! self::isAdmin($user)) {
            $request->forceFill([
                'status' => CashbackRequestStatus::PendingAdmin,
                'manager_approved_by' => $user->getKey(),
                'manager_approved_at' => now(),
                'manager_note' => $note,
            ])->save();

            $this->notify($this->admins(), 'Cashback form to approve: '.$request->customer?->customer_name, sprintf('%s approved %s for ₹%s. It needs the Admin\'s approval.', $user->name, $request->voucher_no, indianNumberFormat($request->amount)), 'warning', $request);
            $this->notifyRequester($request, 'approved by the Manager — now with the Admin', 'info', $note);

            return $request;
        }

        $approvedAmount = $amount === null || $amount === '' ? (float) $request->amount : $this->validAmount($amount, 'approved_amount', allowZero: false);

        DB::transaction(function () use ($request, $user, $note, $approvedAmount): void {
            $request->forceFill([
                'status' => CashbackRequestStatus::Approved,
                'approved_amount' => $approvedAmount,
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
                'approval_note' => $note,
            ])->save();

            $this->syncCustomer($request->customer);
        });

        $this->notifyRequester($request, 'approved', 'success', $note);
        $this->notify(
            User::query()->role('Accounts')->where('is_active', true)->get(),
            'Cashback approved: '.$request->customer?->customer_name,
            sprintf('%s for ₹%s is approved and ready to pay.', $request->voucher_no, indianNumberFormat($approvedAmount)),
            'success',
            $request,
        );

        return $request;
    }

    public function reject(CustomerCashbackRequest $request, User $user, string $reason): CustomerCashbackRequest
    {
        if (! $this->canReject($user, $request)) {
            throw new AuthorizationException('You cannot reject this cashback form.');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'Say why the form is rejected.']);
        }

        DB::transaction(function () use ($request, $user, $reason): void {
            $request->forceFill([
                'status' => CashbackRequestStatus::Rejected,
                'rejected_by' => $user->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            $this->syncCustomer($request->customer);
        });

        $this->notifyRequester($request, 'rejected', 'danger', $reason);

        return $request;
    }

    /**
     * An expected cashback no longer needed: the form is closed and the
     * file's cashback goes back to 0, so the figures recover it.
     */
    public function retrieve(CustomerCashbackRequest $request, User $user, string $reason): CustomerCashbackRequest
    {
        if (! $this->canRetrieve($user, $request)) {
            throw new AuthorizationException($request->isPaid()
                ? 'A paid cashback cannot be retrieved.'
                : 'Only an unpaid expected cashback can be retrieved, by the file\'s Team Leader or Manager.');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['retrieval_reason' => 'Say why the expected cashback is not needed.']);
        }

        DB::transaction(function () use ($request, $user, $reason): void {
            $request->forceFill([
                'status' => CashbackRequestStatus::Retrieved,
                'retrieved_by' => $user->getKey(),
                'retrieved_at' => now(),
                'retrieval_reason' => trim($reason),
            ])->save();

            $this->syncCustomer($request->customer);
        });

        $this->notify(
            $this->paymentUsers()->merge(collect([$request->requester]))->filter()->unique('id')->reject(fn (User $recipient): bool => $recipient->is($user)),
            'Expected cashback retrieved: '.$request->customer?->customer_name,
            sprintf('%s retrieved %s (₹%s) — do not pay it. Reason: %s', $user->name, $request->voucher_no, indianNumberFormat($request->effectiveAmount()), str($reason)->limit(120)),
            'gray',
            $request,
        );

        return $request;
    }

    /** The Admin's deviation from an approved amount; logged with the reason. */
    public function changeAmount(CustomerCashbackRequest $request, User $user, mixed $amount, string $reason): CustomerCashbackRequest
    {
        if (! $this->canChangeAmount($user, $request)) {
            throw new AuthorizationException('Only the Admin can change an approved cashback amount.');
        }

        $newAmount = $this->validAmount($amount, 'approved_amount', allowZero: true);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the amount is changing.']);
        }

        $oldAmount = $request->effectiveAmount();

        if ($oldAmount === $newAmount) {
            throw ValidationException::withMessages(['approved_amount' => 'This is already the approved amount.']);
        }

        DB::transaction(function () use ($request, $user, $newAmount, $oldAmount, $reason): void {
            $request->forceFill(['approved_amount' => $newAmount])->save();

            $this->syncCustomer($request->customer);

            activity('cashback')
                ->performedOn($request)
                ->causedBy($user)
                ->event('updated')
                ->withProperties(['old_amount' => $oldAmount, 'new_amount' => $newAmount, 'reason' => trim($reason)])
                ->log('Cashback amount changed by the Admin');
        });

        $this->notifyRequester($request, sprintf('amount changed by the Admin from ₹%s to ₹%s', indianNumberFormat($oldAmount), indianNumberFormat($newAmount)), 'warning', $reason);

        return $request;
    }

    /**
     * Payment details and status, kept by the Admin / Accounts. Paid needs
     * the mode, the date and (except cash) the UTR; On Hold needs a reason.
     *
     * @param  array{payment_status?: string|null, payment_mode?: string|null, upi_platform?: string|null, payee_upi?: string|null, payee_account_no?: string|null, payee_ifsc?: string|null, transaction_id?: string|null, payment_date?: string|null, payment_remarks?: string|null}  $data
     */
    public function updatePayment(CustomerCashbackRequest $request, User $user, array $data): CustomerCashbackRequest
    {
        if (! $this->canUpdatePayment($user, $request)) {
            throw new AuthorizationException('Only the Admin or Accounts can update the payment of an approved cashback form.');
        }

        $status = CashbackPaymentStatus::tryFrom((string) ($data['payment_status'] ?? ''))
            ?? throw ValidationException::withMessages(['payment_status' => 'Choose Paid, Not Paid or On Hold.']);

        $paid = $status === CashbackPaymentStatus::Paid;
        $payee = $this->validPayee($data, required: $paid);
        $mode = CashbackPaymentMode::tryFrom((string) ($payee['payment_mode'] ?? ''));
        $transactionId = filled($data['transaction_id'] ?? null) ? trim((string) $data['transaction_id']) : null;
        $paymentDate = filled($data['payment_date'] ?? null) ? date('Y-m-d', (int) strtotime((string) $data['payment_date'])) : null;
        $remarks = filled($data['payment_remarks'] ?? null) ? trim((string) $data['payment_remarks']) : null;

        if ($paid && $mode?->needsTransactionId() && $transactionId === null) {
            throw ValidationException::withMessages(['transaction_id' => 'Enter the UTR / transaction ID of the payment.']);
        }

        if ($paid && $paymentDate === null) {
            throw ValidationException::withMessages(['payment_date' => 'Enter the date it was paid.']);
        }

        if ($paymentDate !== null && $paymentDate > today()->toDateString()) {
            throw ValidationException::withMessages(['payment_date' => 'The payment date cannot be in the future.']);
        }

        if ($status === CashbackPaymentStatus::OnHold && $remarks === null) {
            throw ValidationException::withMessages(['payment_remarks' => 'Say why the payment is on hold.']);
        }

        $previous = $request->payment_status;

        $request->forceFill([
            ...$payee,
            'payment_status' => $status,
            'transaction_id' => $transactionId,
            'payment_date' => $paymentDate,
            'paid_by' => $paid ? ($request->isPaid() && $request->paid_by ? $request->paid_by : $user->getKey()) : null,
            'payment_remarks' => $remarks,
            'payment_updated_by' => $user->getKey(),
            'payment_updated_at' => now(),
        ])->save();

        if ($previous !== $status) {
            $this->notifyRequester($request, 'marked '.$status->label(), $status->color(), $remarks);
        }

        return $request;
    }

    /**
     * What the disbursal step writes to customers.cashback for a declaration,
     * refusing to go ahead while a declared cashback has no form.
     *
     * @throws ValidationException
     */
    public function cashbackForDeclaration(Customer $customer, ?string $option): float
    {
        $declared = CashbackType::tryFrom((string) $option)
            ?? throw ValidationException::withMessages(['cashback_option' => 'Declare whether this file has cashback, expected cashback or none.']);

        $live = $this->liveRequest($customer);

        if (! $declared->needsForm()) {
            if ($live !== null) {
                throw ValidationException::withMessages(['cashback_option' => "This file has cashback form {$live->voucher_no} — it cannot be declared as no cashback."]);
            }

            return 0.0;
        }

        if ($live === null || $live->type !== $declared) {
            throw ValidationException::withMessages(['cashback_option' => 'Fill the '.strtolower($declared->label()).' form for this file before going ahead.']);
        }

        return $live->effectiveAmount();
    }

    /**
     * customers.cashback follows the live form (0 when none is live); the
     * declaration follows the live form's type.
     */
    public function syncCustomer(?Customer $customer): void
    {
        if ($customer === null) {
            return;
        }

        $live = $this->liveRequest($customer);

        $customer->forceFill([
            'cashback' => $live?->effectiveAmount() ?? 0,
            'cashback_option' => $live?->type->value ?? $customer->cashback_option,
        ])->save();
    }

    public static function voucherNumber(int $id): string
    {
        return sprintf('CB-%06d', $id);
    }

    /** Team Leader and above on the file's chain (or a stand-in), or the Admin. */
    private function mayFillFor(?User $user, Customer $customer): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::isAdmin($user)) {
            return true;
        }

        return $user->hasAnyRole(self::RAISER_ROLES)
            && $this->eligibility->canWorkOn($user, $customer);
    }

    private function signsForManager(User $user, CustomerCashbackRequest $request): bool
    {
        return $request->customer !== null
            && $user->hasAnyRole(self::MANAGER_ROLES)
            && (int) $request->requested_by !== (int) $user->getKey()
            && $this->eligibility->canWorkOn($user, $request->customer);
    }

    /** The owner themself when they hold $designation, else their nearest such boss. */
    private function chainMember(?Employee $owner, int $designation): ?Employee
    {
        if ($owner === null) {
            return null;
        }

        if ((int) $owner->designation === $designation) {
            return $owner;
        }

        return HierarchyHelper::nearestAncestor($owner, [$designation]);
    }

    private function validAmount(mixed $value, string $key, bool $allowZero): float
    {
        $clean = str_replace([',', '₹', ' '], '', trim((string) $value));

        if ($clean === '' || ! is_numeric($clean) || (float) $clean < 0 || (! $allowZero && (float) $clean <= 0)) {
            throw ValidationException::withMessages([$key => $allowZero ? 'Enter an amount of zero or more.' : 'Enter an amount above zero.']);
        }

        return round((float) $clean, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment_mode: string|null, upi_platform: string|null, payee_upi: string|null, payee_account_no: string|null, payee_ifsc: string|null}
     */
    private function validPayee(array $data, bool $required): array
    {
        $mode = CashbackPaymentMode::tryFrom((string) ($data['payment_mode'] ?? ''));

        if ($mode === null) {
            if ($required) {
                throw ValidationException::withMessages(['payment_mode' => 'Choose how the cashback is to be paid.']);
            }

            return ['payment_mode' => null, 'upi_platform' => null, 'payee_upi' => null, 'payee_account_no' => null, 'payee_ifsc' => null];
        }

        $platform = filled($data['upi_platform'] ?? null) ? (string) $data['upi_platform'] : null;
        $upi = filled($data['payee_upi'] ?? null) ? trim((string) $data['payee_upi']) : null;
        $account = filled($data['payee_account_no'] ?? null) ? preg_replace('/\s+/', '', (string) $data['payee_account_no']) : null;
        $ifsc = filled($data['payee_ifsc'] ?? null) ? strtoupper(trim((string) $data['payee_ifsc'])) : null;

        if ($mode === CashbackPaymentMode::Upi) {
            if (! array_key_exists((string) $platform, CashbackPaymentMode::UPI_PLATFORMS)) {
                throw ValidationException::withMessages(['upi_platform' => 'Choose the UPI app.']);
            }

            if ($upi === null) {
                throw ValidationException::withMessages(['payee_upi' => 'Enter the customer\'s UPI ID or UPI number.']);
            }

            return ['payment_mode' => $mode->value, 'upi_platform' => $platform, 'payee_upi' => $upi, 'payee_account_no' => null, 'payee_ifsc' => null];
        }

        if ($mode->needsBankAccount()) {
            if ($account === null || ! preg_match('/^\d{6,20}$/', $account)) {
                throw ValidationException::withMessages(['payee_account_no' => 'Enter the customer\'s bank account number (digits only).']);
            }

            if ($ifsc === null || ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                throw ValidationException::withMessages(['payee_ifsc' => 'Enter a valid IFSC like HDFC0001234.']);
            }

            return ['payment_mode' => $mode->value, 'upi_platform' => null, 'payee_upi' => null, 'payee_account_no' => $account, 'payee_ifsc' => $ifsc];
        }

        return ['payment_mode' => $mode->value, 'upi_platform' => null, 'payee_upi' => null, 'payee_account_no' => null, 'payee_ifsc' => null];
    }

    /**
     * Who signs a Team Leader's form off: the file's Manager, else the
     * nearest Cluster Manager / Business Head above the owner, plus anyone
     * standing in for the owner. Falls back to the Admin.
     *
     * @return Collection<int, User>
     */
    private function managerStageUsers(CustomerCashbackRequest $request): Collection
    {
        $owner = $request->aro;
        $signer = $request->manager
            ?? ($owner ? HierarchyHelper::nearestAncestor($owner, [Employee::DESIGNATION_CLUSTER, Employee::DESIGNATION_BUSINESS_HEAD]) : null);

        $users = $signer
            ? User::query()->where('employee_id', $signer->getKey())->where('is_active', true)->get()
            : collect();

        return $users->isNotEmpty() ? $users : $this->admins();
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::query()->role('Admin')->where('is_active', true)->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function paymentUsers(): Collection
    {
        return User::query()->role(self::PAYMENT_ROLES)->where('is_active', true)->get();
    }

    private function notifyRequester(CustomerCashbackRequest $request, string $outcome, string $color, ?string $note): void
    {
        $requester = $request->requester;

        if (! $requester) {
            return;
        }

        $this->notify(
            collect([$requester]),
            'Cashback form '.$outcome.': '.$request->customer?->customer_name,
            trim($request->voucher_no.' — ₹'.indianNumberFormat($request->effectiveAmount()).'.'.(filled($note) ? ' Note: '.$note : '')),
            $color,
            $request,
        );
    }

    /**
     * @param  Collection<int, User>|iterable<int, User>  $recipients
     */
    private function notify(iterable $recipients, string $title, string $body, string $color, CustomerCashbackRequest $request): void
    {
        $recipients = collect($recipients)->filter()->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon(NotificationCategory::Cashback->icon())
                ->iconColor($color)
                ->viewData(NotificationCategory::Cashback->viewData())
                ->actions([
                    Action::make('open')
                        ->label('Open cashback form')
                        ->url(CustomerCashbackRequestResource::getUrl('view', ['record' => $request]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (Throwable $exception) {
            // A failed notification must never block the cashback form itself.
            report($exception);
        }
    }
}
