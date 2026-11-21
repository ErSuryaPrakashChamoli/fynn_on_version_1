<?php

namespace App\Services;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackReturnStatus;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single write path for cashback forms and for customers.cashback on
 * files declared for cashback (the digital "Acknowledgment" voucher).
 *
 *  - A Team Leader or anyone above them on the file's chain fills the form.
 *    A Team Leader's goes first to their own supervisor (their nearest
 *    active Manager / Cluster Manager / Business Head), who has 24 hours
 *    (SUPERVISOR_TAT_HOURS) to approve or reject it; after that it moves to
 *    the Admin on its own (escalateOverdue(), run by cashback:escalate-overdue).
 *    A Manager's (or Cluster Manager's / Business Head's) goes straight to
 *    the Admin; the Admin's own is approved as it is filled. The Admin can
 *    approve at any stage.
 *  - A case can get a new cashback any time after it is punched in (from
 *    the Cashback Forms screen, picking the case), so it may carry several
 *    forms; only one may wait for approval at a time. customers.cashback
 *    always equals the live (pending or approved) forms' amounts plus any
 *    cashback the case carried before its first form
 *    (customers.cashback_before_forms) — so the achievement and incentive
 *    maths, which already read customers.cashback, follow every form, its
 *    approval, a retrieval or the Admin's change with no change of their own.
 *  - After approval only the Admin changes the amount; the Admin or
 *    Accounts record the payment (mode, UTR, date, Paid / Not Paid / On Hold).
 *  - An unpaid expected cashback can be retrieved by the Team Leader or
 *    Manager when it is not needed any more; the file's cashback drops to 0.
 *  - A paid cashback that has to be paid a second time is revised
 *    (revise()): the revision is a new form on the same case, linked to the
 *    original, for the extra amount, approved through the same process and
 *    paid with its own UTR. A UTR is never recorded on two forms.
 *  - A paid cashback the customer gives back (in full or in part) is
 *    requested by the case's Team Leader or Manager (requestReturn()) and
 *    counts only once the Admin approves it (approveReturn()): then the
 *    case's cashback drops by the returned amount, so the figures recover
 *    it. A rejected return can be asked for again.
 */
class CustomerCashbackService
{
    /** Who may fill a cashback form (besides the Admin). */
    public const RAISER_ROLES = ['Team Leader', 'Manager', 'Cluster Manager', 'Business Head'];

    /** Who signs a Team Leader's form off before it reaches the Admin. */
    public const MANAGER_ROLES = ['Manager', 'Cluster Manager', 'Business Head'];

    /** Who records the payment against an approved form. */
    public const PAYMENT_ROLES = ['Admin', 'Accounts'];

    /** Hours the supervisor has before a Team Leader's form moves to the Admin. */
    public const SUPERVISOR_TAT_HOURS = 24;

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

    /**
     * The case's pending and approved forms, oldest first.
     *
     * @return Collection<int, CustomerCashbackRequest>
     */
    public function liveRequests(Customer $customer): Collection
    {
        return $customer->cashbackRequests()
            ->live()
            ->oldest('id')
            ->get();
    }

    public function hasLiveForms(Customer $customer): bool
    {
        return $customer->cashbackRequests()->live()->exists();
    }

    /** The one form on the case still waiting for approval, if any. */
    public function pendingRequest(Customer $customer): ?CustomerCashbackRequest
    {
        return $customer->cashbackRequests()
            ->pending()
            ->latest('id')
            ->first();
    }

    /**
     * The owner's chain (Team Leader and up) or a continuity stand-in, or
     * the Admin — on a case at Step 4 or already disbursed (any earlier day
     * or month), with no other form waiting for approval.
     */
    public function canRaiseOn(?User $user, Customer $customer): bool
    {
        return $this->mayFillFor($user, $customer)
            && in_array(strtolower((string) $customer->journey_status), self::OPEN_JOURNEY_STATUSES, true)
            && $this->pendingRequest($customer) === null;
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
            && ! $request->isPastSupervisorTat()
            && $this->signsAsSupervisor($user, $request);
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

    /**
     * A paid form (not itself a revision) can be revised for a second
     * payment by whoever may fill forms on the case, while no other form on
     * the case waits for approval.
     */
    public function canRevise(?User $user, CustomerCashbackRequest $request): bool
    {
        return $request->isApproved()
            && $request->isPaid()
            && ! $request->isRevision()
            && $request->customer !== null
            && $this->canRaiseOn($user, $request->customer);
    }

    /**
     * A cashback that reached the customer can be asked to be recorded as
     * returned (in full or in part) by whoever may fill forms on the case,
     * while no return on it is waiting or approved.
     */
    public function canRequestReturn(?User $user, CustomerCashbackRequest $request): bool
    {
        return $request->isApproved()
            && $request->isPaid()
            && ! $request->isReturned()
            && ! $request->hasPendingReturn()
            && $request->effectiveAmount() > 0
            && $request->customer !== null
            && $this->mayFillFor($user, $request->customer);
    }

    /** Only the Admin approves or rejects a requested return. */
    public function canReviewReturn(?User $user, CustomerCashbackRequest $request): bool
    {
        return self::isAdmin($user) && $request->hasPendingReturn();
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
    public function raise(User $user, Customer $customer, array $data, ?CustomerCashbackRequest $revisionOf = null): CustomerCashbackRequest
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

        $request = DB::transaction(function () use ($user, $customer, $type, $amount, $payee, $data, $revisionOf): CustomerCashbackRequest {
            /** @var Customer $customer */
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());

            if ($pending = $this->pendingRequest($customer)) {
                throw ValidationException::withMessages(['type' => "This case already has cashback form {$pending->voucher_no} waiting for approval — it has to be approved or rejected first."]);
            }

            // The first form keeps whatever cashback the case already carried.
            if ($customer->cashback_before_forms === null) {
                $customer->forceFill(['cashback_before_forms' => $this->cashbackBeforeForms($customer)]);
            }

            $now = now();
            $owner = $customer->assign_to ? Employee::query()->find($customer->assign_to) : null;
            $supervisor = self::isAdmin($user) || $user->hasAnyRole(self::MANAGER_ROLES) ? null : $this->supervisorOf($user);
            $status = match (true) {
                self::isAdmin($user) => CashbackRequestStatus::Approved,
                $user->hasAnyRole(self::MANAGER_ROLES) => CashbackRequestStatus::PendingAdmin,
                // Nobody above the filler with a login to approve: the Admin has it.
                $supervisor === null => CashbackRequestStatus::PendingAdmin,
                default => CashbackRequestStatus::PendingManager,
            };

            $revisionNo = $revisionOf ? ((int) $revisionOf->revisions()->max('revision_no')) + 1 : null;

            $request = CustomerCashbackRequest::query()->create([
                'customer_id' => $customer->getKey(),
                'revision_of_id' => $revisionOf?->getKey(),
                'revision_no' => $revisionNo,
                'type' => $type,
                'amount' => $amount,
                'approved_amount' => $status === CashbackRequestStatus::Approved ? $amount : null,
                'status' => $status,
                'remarks' => filled($data['remarks'] ?? null) ? trim((string) $data['remarks']) : null,
                ...$payee,
                'aro_employee_id' => $owner?->getKey(),
                'team_leader_employee_id' => $this->chainMember($owner, Employee::DESIGNATION_TEAM_LEADER)?->getKey(),
                'manager_employee_id' => $this->chainMember($owner, Employee::DESIGNATION_MANAGER)?->getKey(),
                'cluster_manager_employee_id' => $this->chainMember($owner, Employee::DESIGNATION_CLUSTER)?->getKey(),
                'supervisor_employee_id' => $supervisor?->getKey(),
                'supervisor_due_at' => $status === CashbackRequestStatus::PendingManager ? $now->copy()->addHours(self::SUPERVISOR_TAT_HOURS) : null,
                'requested_by' => $user->getKey(),
                'manager_approved_by' => $user->hasAnyRole(self::MANAGER_ROLES) && ! self::isAdmin($user) ? $user->getKey() : null,
                'manager_approved_at' => $user->hasAnyRole(self::MANAGER_ROLES) && ! self::isAdmin($user) ? $now : null,
                'approved_by' => $status === CashbackRequestStatus::Approved ? $user->getKey() : null,
                'approved_at' => $status === CashbackRequestStatus::Approved ? $now : null,
                'payment_status' => CashbackPaymentStatus::NotPaid,
            ]);

            $request->forceFill([
                'voucher_no' => $revisionOf ? $revisionOf->voucher_no.'/R'.$revisionNo : self::voucherNumber($request->getKey()),
            ])->saveQuietly();

            $this->syncCustomer($customer);

            return $request;
        });

        $summary = $revisionOf
            ? sprintf('%s revised paid cashback %s for a second payment of ₹%s.', $user->name, $revisionOf->voucher_no, indianNumberFormat($amount))
            : sprintf('%s filled a %s form for ₹%s.', $user->name, strtolower($type->label()), indianNumberFormat($amount));

        match ($request->status) {
            CashbackRequestStatus::PendingManager => $this->notify(
                $this->supervisorUsers($request),
                'Cashback form to approve: '.$customer->customer_name,
                $summary.sprintf(' Approve or reject it within %d hours (by %s) — after that it moves to the Admin.', self::SUPERVISOR_TAT_HOURS, $request->supervisor_due_at?->format('d M, h:i A')),
                'warning',
                $request,
            ),
            CashbackRequestStatus::PendingAdmin => $this->notify($this->admins(), 'Cashback form to approve: '.$customer->customer_name, $summary, 'warning', $request),
            default => $this->notify($this->paymentUsers()->reject(fn (User $recipient): bool => $recipient->is($user)), 'Cashback approved: '.$customer->customer_name, $summary.' Approved by the Admin — ready to pay.', 'success', $request),
        };

        return $request;
    }

    /**
     * A second payment on a paid cashback: a new form on the same case,
     * linked to $original, of the same kind, for the extra amount, with a
     * reason. It goes through the same approval (supervisor within the TAT,
     * then the Admin) and is paid with its own UTR.
     *
     * @param  array{amount?: mixed, remarks?: string|null, payment_mode?: string|null, upi_platform?: string|null, payee_upi?: string|null, payee_account_no?: string|null, payee_ifsc?: string|null}  $data
     */
    public function revise(User $user, CustomerCashbackRequest $original, array $data): CustomerCashbackRequest
    {
        if (! $this->canRevise($user, $original)) {
            throw new AuthorizationException(match (true) {
                $original->isRevision() => 'Revise the original cashback form, not a revision.',
                ! $original->isPaid() => 'Only a paid cashback can be revised for a second payment.',
                $original->customer && $this->pendingRequest($original->customer) => 'Another cashback form on this case is waiting for approval — it has to be approved or rejected first.',
                default => 'Only the Team Leader or Manager of this case (or the Admin) can revise its cashback.',
            });
        }

        if (blank($data['remarks'] ?? null)) {
            throw ValidationException::withMessages(['remarks' => 'Say why the cashback has to be paid again.']);
        }

        return $this->raise($user, $original->customer, [...$data, 'type' => $original->type->value], $original);
    }

    /**
     * The supervisor's sign-off (within the TAT) moves a Team Leader's form
     * on to the Admin; the Admin's approval (at either stage) is final and
     * may set the amount.
     */
    public function approve(CustomerCashbackRequest $request, User $user, ?string $note = null, mixed $amount = null): CustomerCashbackRequest
    {
        if (! $this->canApprove($user, $request)) {
            throw new AuthorizationException(match (true) {
                $request->isPastSupervisorTat() => 'The '.self::SUPERVISOR_TAT_HOURS.'-hour approval time is over — this cashback form has moved to the Admin.',
                $request->isPending() => 'This cashback form is waiting for someone else to approve it.',
                default => 'This cashback form has already been '.strtolower($request->status->label()).'.',
            });
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
            $this->notifyRequester($request, 'approved by the supervisor — now with the Admin', 'info', $note);

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
            $this->activeUsersWithRoles(['Accounts']),
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

        if ($request->isReturned() || $request->hasPendingReturn()) {
            throw ValidationException::withMessages(['approved_amount' => $request->isReturned()
                ? 'The customer has returned ₹'.indianNumberFormat($request->returnedAmount()).' of this cashback — its amount cannot change any more.'
                : 'A return of this cashback is waiting for the Admin — approve or reject it first.']);
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
     * @param  array{payment_status?: string|null, payment_mode?: string|null, upi_platform?: string|null, payee_upi?: string|null, payee_account_no?: string|null, payee_ifsc?: string|null, transaction_id?: string|null, payment_date?: string|null, paid_by_name?: string|null, payment_remarks?: string|null}  $data
     */
    public function updatePayment(CustomerCashbackRequest $request, User $user, array $data): CustomerCashbackRequest
    {
        if (! $this->canUpdatePayment($user, $request)) {
            throw new AuthorizationException('Only the Admin or Accounts can update the payment of an approved cashback form.');
        }

        $status = CashbackPaymentStatus::tryFrom((string) ($data['payment_status'] ?? ''))
            ?? throw ValidationException::withMessages(['payment_status' => 'Choose Paid, Not Paid or On Hold.']);

        $paid = $status === CashbackPaymentStatus::Paid;

        if (($request->isReturned() || $request->hasPendingReturn()) && ! $paid) {
            throw ValidationException::withMessages(['payment_status' => 'A return of this cashback is recorded, so it was paid — its payment stays Paid.']);
        }

        $payee = $this->validPayee($data, required: $paid);
        $mode = CashbackPaymentMode::tryFrom((string) ($payee['payment_mode'] ?? ''));
        $transactionId = filled($data['transaction_id'] ?? null) ? trim((string) $data['transaction_id']) : null;
        $paymentDate = filled($data['payment_date'] ?? null) ? date('Y-m-d', (int) strtotime((string) $data['payment_date'])) : null;
        $remarks = filled($data['payment_remarks'] ?? null) ? trim((string) $data['payment_remarks']) : null;

        if ($paid && $mode?->needsTransactionId() && $transactionId === null) {
            throw ValidationException::withMessages(['transaction_id' => 'Enter the UTR / transaction ID of the payment.']);
        }

        if ($transactionId !== null) {
            $sameUtr = CustomerCashbackRequest::query()
                ->whereKeyNot($request->getKey())
                ->where('transaction_id', $transactionId)
                ->value('voucher_no');

            if ($sameUtr !== null) {
                throw ValidationException::withMessages(['transaction_id' => "This UTR is already recorded on cashback form {$sameUtr}. A second payment needs its own UTR — revise the cashback for it."]);
            }
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

        $paidByName = filled($data['paid_by_name'] ?? null) ? trim((string) $data['paid_by_name']) : null;
        $previous = $request->payment_status;

        $request->forceFill([
            ...$payee,
            'payment_status' => $status,
            'transaction_id' => $transactionId,
            'payment_date' => $paymentDate,
            'paid_by' => $paid ? ($request->isPaid() && $request->paid_by ? $request->paid_by : $user->getKey()) : null,
            'paid_by_name' => $paid ? ($paidByName ?? $user->name) : null,
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
     * The case's Team Leader or Manager asks for a paid cashback the
     * customer gave back (in full or in part) to be recorded: how much,
     * when, how (mode + UTR, except cash) and why. Nothing changes on the
     * case until the Admin approves it.
     *
     * @param  array{returned_amount?: mixed, return_date?: string|null, return_mode?: string|null, return_reference?: string|null, return_reason?: string|null}  $data
     */
    public function requestReturn(CustomerCashbackRequest $request, User $user, array $data): CustomerCashbackRequest
    {
        if (! $this->canRequestReturn($user, $request)) {
            throw new AuthorizationException(match (true) {
                $request->hasPendingReturn() => 'A return of this cashback is already waiting for the Admin.',
                $request->isReturned() => 'The return of this cashback is already approved.',
                ! $request->isPaid() => 'Only a cashback that was paid can be returned — an unpaid one is retrieved or its amount changed instead.',
                default => 'Only the Team Leader or Manager of this case can ask for its cashback return to be recorded.',
            });
        }

        $amount = $this->validAmount($data['returned_amount'] ?? null, 'returned_amount', allowZero: false);

        if ($amount > $request->effectiveAmount()) {
            throw ValidationException::withMessages(['returned_amount' => 'Only ₹'.indianNumberFormat($request->effectiveAmount()).' was paid on this form — the return cannot be more.']);
        }

        $mode = CashbackPaymentMode::tryFrom((string) ($data['return_mode'] ?? ''))
            ?? throw ValidationException::withMessages(['return_mode' => 'Choose how the customer returned it.']);
        $reference = filled($data['return_reference'] ?? null) ? trim((string) $data['return_reference']) : null;
        $returnDate = filled($data['return_date'] ?? null) ? date('Y-m-d', (int) strtotime((string) $data['return_date'])) : null;
        $reason = trim((string) ($data['return_reason'] ?? ''));

        if ($mode->needsTransactionId() && $reference === null) {
            throw ValidationException::withMessages(['return_reference' => 'Enter the UTR / transaction ID of the money returned.']);
        }

        if ($reference !== null) {
            $sameReference = CustomerCashbackRequest::query()
                ->where(fn (Builder $query): Builder => $query
                    ->where('transaction_id', $reference)
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->where('return_reference', $reference)
                        ->whereKeyNot($request->getKey())
                        ->where('return_status', '!=', CashbackReturnStatus::Rejected->value)))
                ->value('voucher_no');

            if ($sameReference !== null) {
                throw ValidationException::withMessages(['return_reference' => "This UTR is already recorded on cashback form {$sameReference}."]);
            }
        }

        if ($returnDate === null) {
            throw ValidationException::withMessages(['return_date' => 'Enter the date it was returned.']);
        }

        if ($returnDate > today()->toDateString()) {
            throw ValidationException::withMessages(['return_date' => 'The return date cannot be in the future.']);
        }

        if ($request->payment_date !== null && $returnDate < $request->payment_date->toDateString()) {
            throw ValidationException::withMessages(['return_date' => 'It was paid on '.$request->payment_date->format('d M Y').' — it cannot be returned before that.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['return_reason' => 'Say why the cashback was returned.']);
        }

        $request->forceFill([
            'return_status' => CashbackReturnStatus::Pending,
            'returned_amount' => $amount,
            'return_date' => $returnDate,
            'return_mode' => $mode,
            'return_reference' => $reference,
            'return_reason' => $reason,
            'return_requested_by' => $user->getKey(),
            'return_requested_at' => now(),
            'return_reviewed_by' => null,
            'return_reviewed_at' => null,
            'return_review_note' => null,
        ])->save();

        $this->notify(
            $this->admins()->reject(fn (User $recipient): bool => $recipient->is($user)),
            'Cashback return to approve: '.$request->customer?->customer_name,
            sprintf(
                '%s says the customer returned ₹%s of %s (₹%s paid)%s. Reason: %s. It counts only once you approve it.',
                $user->name,
                indianNumberFormat($amount),
                $request->voucher_no,
                indianNumberFormat($request->effectiveAmount()),
                $reference !== null ? ' — UTR '.$reference : '',
                str($reason)->limit(120),
            ),
            'warning',
            $request,
        );

        return $request;
    }

    /**
     * The Admin confirms the requested return: from now on the case's
     * cashback is less the returned amount.
     */
    public function approveReturn(CustomerCashbackRequest $request, User $user, ?string $note = null): CustomerCashbackRequest
    {
        if (! $this->canReviewReturn($user, $request)) {
            throw new AuthorizationException($request->hasPendingReturn()
                ? 'Only the Admin can approve a cashback return.'
                : 'No return of this cashback is waiting for approval.');
        }

        $note = filled($note) ? trim((string) $note) : null;

        DB::transaction(function () use ($request, $user, $note): void {
            $request->forceFill([
                'return_status' => CashbackReturnStatus::Approved,
                'return_reviewed_by' => $user->getKey(),
                'return_reviewed_at' => now(),
                'return_review_note' => $note,
            ])->save();

            $this->syncCustomer($request->customer);
        });

        $this->notify(
            $this->paymentUsers()->merge(collect([$request->returnRequester, $request->requester]))->filter()->unique('id')->reject(fn (User $recipient): bool => $recipient->is($user)),
            'Cashback return approved: '.$request->customer?->customer_name,
            sprintf('%s approved the return of ₹%s on %s. The case\'s cashback is now ₹%s.%s', $user->name, indianNumberFormat($request->returnedAmount()), $request->voucher_no, indianNumberFormat($request->customer?->cashback ?? 0), $note !== null ? ' Note: '.$note : ''),
            'success',
            $request,
        );

        return $request;
    }

    public function rejectReturn(CustomerCashbackRequest $request, User $user, string $reason): CustomerCashbackRequest
    {
        if (! $this->canReviewReturn($user, $request)) {
            throw new AuthorizationException('Only the Admin can reject a cashback return waiting for approval.');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['return_review_note' => 'Say why the return is rejected.']);
        }

        $request->forceFill([
            'return_status' => CashbackReturnStatus::Rejected,
            'return_reviewed_by' => $user->getKey(),
            'return_reviewed_at' => now(),
            'return_review_note' => trim($reason),
        ])->save();

        $this->notify(
            collect([$request->returnRequester])->filter()->reject(fn (User $recipient): bool => $recipient->is($user)),
            'Cashback return rejected: '.$request->customer?->customer_name,
            sprintf('%s rejected the return of ₹%s on %s. Reason: %s', $user->name, indianNumberFormat($request->returned_amount), $request->voucher_no, trim($reason)),
            'danger',
            $request,
        );

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

        $live = $this->liveRequests($customer);

        if (! $declared->needsForm()) {
            if ($live->isNotEmpty()) {
                throw ValidationException::withMessages(['cashback_option' => "This file has cashback form {$live->first()->voucher_no} — it cannot be declared as no cashback."]);
            }

            return 0.0;
        }

        if (! $live->contains(fn (CustomerCashbackRequest $request): bool => $request->type === $declared)) {
            throw ValidationException::withMessages(['cashback_option' => 'Fill the '.strtolower($declared->label()).' form for this file before going ahead.']);
        }

        return $this->totalFor($customer, $live);
    }

    /**
     * The declaration the live forms imply: Cashback if any is a cashback,
     * else Expected if any is expected; null when no form is live.
     */
    public function optionFromForms(Customer $customer): ?string
    {
        $types = $this->liveRequests($customer)->map(fn (CustomerCashbackRequest $request): CashbackType => $request->type);

        return match (true) {
            $types->contains(CashbackType::Cashback) => CashbackType::Cashback->value,
            $types->contains(CashbackType::Expected) => CashbackType::Expected->value,
            default => null,
        };
    }

    /**
     * customers.cashback = the cashback carried before the first form plus
     * every live form's amount, less anything the customer returned; the
     * declaration follows the live forms.
     */
    public function syncCustomer(?Customer $customer): void
    {
        if ($customer === null) {
            return;
        }

        $customer->forceFill([
            'cashback' => $this->totalFor($customer, $this->liveRequests($customer)),
            'cashback_option' => $this->optionFromForms($customer) ?? $customer->cashback_option,
        ])->save();
    }

    /**
     * @param  Collection<int, CustomerCashbackRequest>  $live
     */
    private function totalFor(Customer $customer, Collection $live): float
    {
        return round(
            (float) ($customer->cashback_before_forms ?? 0)
                + $live->sum(fn (CustomerCashbackRequest $request): float => $request->netAmount()),
            2,
        );
    }

    /**
     * What a case carried before its first form: the typed cashback of an
     * already disbursed case. A case still at Step 4 never counted, so 0.
     */
    private function cashbackBeforeForms(Customer $customer): float
    {
        $disbursed = strtolower((string) $customer->journey_status) === 'sanctioned' || (bool) $customer->disbursal_finalized;

        return $disbursed ? (float) ($customer->cashback ?? 0) : 0.0;
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

    /**
     * Moves every Team Leader's form whose supervisor let the TAT run out on
     * to the Admin, and tells the Admin, the supervisor and the filler.
     * Returns how many moved.
     */
    public function escalateOverdue(?Carbon $now = null): int
    {
        $now ??= now();
        $moved = 0;

        $ids = CustomerCashbackRequest::query()->overdueForSupervisor($now)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $request = DB::transaction(function () use ($id, $now): ?CustomerCashbackRequest {
                $request = CustomerCashbackRequest::query()->lockForUpdate()->find($id);

                // The supervisor may have acted between the query and the lock.
                if (! $request || ! $request->isPastSupervisorTat($now)) {
                    return null;
                }

                $request->forceFill([
                    'status' => CashbackRequestStatus::PendingAdmin,
                    'escalated_at' => $now,
                ])->save();

                return $request;
            });

            if ($request === null) {
                continue;
            }

            $moved++;
            $supervisor = $request->supervisor?->emp_name ?? 'The supervisor';
            $summary = sprintf('%s (₹%s) was not approved by %s within %d hours.', $request->voucher_no, indianNumberFormat($request->amount), $supervisor, self::SUPERVISOR_TAT_HOURS);

            $this->notify($this->admins(), 'Cashback form to approve: '.$request->customer?->customer_name, $summary.' It has moved to you for approval.', 'warning', $request);
            $this->notify(
                $this->supervisorUsers($request, fallBackToAdmins: false)->merge(collect([$request->requester]))->filter(),
                'Cashback form moved to the Admin: '.$request->customer?->customer_name,
                $summary.' The Admin will approve or reject it now.',
                'gray',
                $request,
            );
        }

        return $moved;
    }

    /**
     * The supervisor of whoever fills the form: their nearest active boss
     * at Manager level or above who has an active login. Null when there
     * is none, and the form goes to the Admin.
     */
    private function supervisorOf(User $user): ?Employee
    {
        $employee = $user->employee;

        if ($employee === null) {
            return null;
        }

        $supervisor = HierarchyHelper::nearestAncestor(
            $employee,
            [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_CLUSTER, Employee::DESIGNATION_BUSINESS_HEAD],
            activeOnly: true,
        );

        $hasLogin = $supervisor !== null
            && User::query()->where('employee_id', $supervisor->getKey())->where('is_active', true)->exists();

        return $hasLogin ? $supervisor : null;
    }

    /**
     * The form's supervisor, or anyone above them, signs it off — never the
     * person who filled it. Forms from before supervisors were recorded
     * fall back to any Manager on the file's chain.
     */
    private function signsAsSupervisor(User $user, CustomerCashbackRequest $request): bool
    {
        if ($request->customer === null
            || ! $user->hasAnyRole(self::MANAGER_ROLES)
            || (int) $request->requested_by === (int) $user->getKey()) {
            return false;
        }

        if ($request->supervisor_employee_id === null) {
            return $this->eligibility->canWorkOn($user, $request->customer);
        }

        $employee = $user->employee;

        return $employee !== null
            && HierarchyHelper::visibleSubordinateIds($employee)->contains((int) $request->supervisor_employee_id);
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
     * The supervisor's logins (falling back to the file's Manager for forms
     * from before supervisors were recorded), else the Admins.
     *
     * @return Collection<int, User>
     */
    private function supervisorUsers(CustomerCashbackRequest $request, bool $fallBackToAdmins = true): Collection
    {
        $signerId = $request->supervisor_employee_id ?? $request->manager_employee_id;

        $users = $signerId
            ? User::query()->where('employee_id', $signerId)->where('is_active', true)->get()
            : collect();

        return $users->isNotEmpty() || ! $fallBackToAdmins ? $users : $this->admins();
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return $this->activeUsersWithRoles(['Admin']);
    }

    /**
     * @return Collection<int, User>
     */
    private function paymentUsers(): Collection
    {
        return $this->activeUsersWithRoles(self::PAYMENT_ROLES);
    }

    /**
     * Active users holding any of $roles. Matched by name on purpose: the
     * role() scope throws RoleDoesNotExist when a role (e.g. Accounts) has
     * not been created on this install, which would break the approval
     * itself; a missing role here just means nobody to notify.
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function activeUsersWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->where('is_active', true)
            ->get();
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
