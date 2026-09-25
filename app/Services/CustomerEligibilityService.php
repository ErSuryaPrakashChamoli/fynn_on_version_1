<?php

namespace App\Services;

use App\Enums\EligibilityLogEvent;
use App\Enums\EligibilityRequestStatus;
use App\Enums\JourneyModule;
use App\Enums\NotificationCategory;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerEligibilityLog;
use App\Models\CustomerEligibilityRequest;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Support\HierarchyHelper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single write path for customers.eligibility_status once a file exists.
 *
 *  - Eligible: final. The file moves on to SFL and nobody can change it.
 *  - Not Eligible: only the Admin can lift it, by approving a request raised
 *    by the owner or anyone above them.
 *  - Consent Pending: the owner or anyone above them may change it any time.
 *
 * Every change, request and review is written to customer_eligibility_logs,
 * shown on the file to its owner, their whole reporting chain and the Admin,
 * who are also notified.
 */
class CustomerEligibilityService
{
    public const ELIGIBLE = 'eligible';

    public const NOT_ELIGIBLE = 'not_eligible';

    public const CONSENT_PENDING = 'consent_pending';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::ELIGIBLE => 'Eligible',
        self::NOT_ELIGIBLE => 'Not Eligible',
        self::CONSENT_PENDING => 'Consent Pending',
    ];

    /** @var array<string, string> */
    public const NOT_ELIGIBLE_REASONS = [
        'company_not_listed' => 'Company Not Listed',
        'cibil_score' => 'CIBIL Score',
        'defaulter_bounces' => 'Defaulter / Bounces',
        'no_residence_proof' => 'No Residence Proof',
        'low_salary' => 'Low Salary',
        'location_issue' => 'Location',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ($status ? str($status)->headline()->toString() : '—');
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::ELIGIBLE => 'success',
            self::NOT_ELIGIBLE => 'danger',
            self::CONSENT_PENDING => 'warning',
            default => 'gray',
        };
    }

    /** Only the Admin reviews eligibility requests. */
    public static function isReviewer(?User $user): bool
    {
        return $user !== null && $user->hasRole('Admin');
    }

    /**
     * The file's owner and everyone above them in the reporting tree, plus
     * the Admin and whoever is standing in for the owner. Other Bank Support
     * never touches eligibility.
     */
    public function canWorkOn(?User $user, Customer $customer): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if (OtherBankSupportService::isSupportUser($user)) {
            return false;
        }

        $employee = $user->employee;

        if ($employee === null || ! $customer->assign_to) {
            return false;
        }

        if (HierarchyHelper::visibleSubordinateIds($employee)->contains((int) $customer->assign_to)) {
            return true;
        }

        // Standing in for the owner (continuity backup or takeover) on the
        // stage the file is in carries the owner's eligibility rights too.
        return app(CustomerJourneyAccessService::class)->actsForOwner($user, $customer, JourneyModule::forCustomer($customer));
    }

    public function canChangeStatus(?User $user, Customer $customer): bool
    {
        return $customer->eligibility_status === self::CONSENT_PENDING
            && $this->canWorkOn($user, $customer);
    }

    public function canRaiseRequest(?User $user, Customer $customer): bool
    {
        return $customer->eligibility_status === self::NOT_ELIGIBLE
            && ! self::isReviewer($user)
            && $this->canWorkOn($user, $customer)
            && $this->pendingRequest($customer) === null;
    }

    public function pendingRequest(Customer $customer): ?CustomerEligibilityRequest
    {
        return $customer->eligibilityRequests()
            ->pending()
            ->with('requester')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, CustomerEligibilityLog>
     */
    public function logs(Customer $customer): Collection
    {
        return $customer->eligibilityLogs()
            ->with('user')
            ->latest('id')
            ->get();
    }

    /** Records the status a file was created with, as the log's first line. */
    public function logCreation(Customer $customer, ?User $user): void
    {
        $this->writeLog($customer, EligibilityLogEvent::Created, null, $customer->eligibility_status, null, $user);
    }

    /**
     * Moves a Consent Pending file to Eligible or Not Eligible.
     *
     * @throws AuthorizationException
     */
    public function changeStatus(User $user, Customer $customer, string $toStatus, ?string $notEligibleReason = null, ?string $remarks = null): void
    {
        if (! in_array($toStatus, [self::ELIGIBLE, self::NOT_ELIGIBLE], true)) {
            throw new AuthorizationException('Eligibility can only be changed to Eligible or Not Eligible.');
        }

        if ($toStatus === self::NOT_ELIGIBLE && ! array_key_exists((string) $notEligibleReason, self::NOT_ELIGIBLE_REASONS)) {
            throw new AuthorizationException('Choose why the file is not eligible.');
        }

        $log = DB::transaction(function () use ($user, $customer, $toStatus, $notEligibleReason, $remarks): CustomerEligibilityLog {
            $current = $this->lockCustomer($customer);

            if (! $this->canChangeStatus($user, $current)) {
                throw new AuthorizationException($this->lockedMessage($current));
            }

            $fromStatus = $current->eligibility_status;

            $customer->forceFill([
                'eligibility_status' => $toStatus,
                'eligibility_reason' => $toStatus === self::NOT_ELIGIBLE ? $notEligibleReason : null,
                'journey_status' => $toStatus === self::ELIGIBLE ? 'sfl' : 'not_started',
            ])->save();

            return $this->writeLog($customer, EligibilityLogEvent::StatusChanged, $fromStatus, $toStatus, $remarks, $user);
        });

        $this->notify($customer, $log, $user);
    }

    /**
     * Asks the Admin to make a Not Eligible file eligible.
     *
     * @throws AuthorizationException
     */
    public function raiseRequest(User $user, Customer $customer, string $reason): CustomerEligibilityRequest
    {
        [$request, $log] = DB::transaction(function () use ($user, $customer, $reason): array {
            $current = $this->lockCustomer($customer);

            if (! $this->canRaiseRequest($user, $current)) {
                throw new AuthorizationException($this->pendingRequest($current)
                    ? 'A request for this file is already waiting for the Admin.'
                    : $this->lockedMessage($current));
            }

            $request = $customer->eligibilityRequests()->create([
                'requested_by' => $user->id,
                'reason' => $reason,
                'status' => EligibilityRequestStatus::Pending,
            ]);

            $log = $this->writeLog($customer, EligibilityLogEvent::RequestRaised, self::NOT_ELIGIBLE, self::ELIGIBLE, $reason, $user, $request);

            return [$request, $log];
        });

        $this->notify($customer, $log, $user);

        return $request;
    }

    /**
     * Approving makes the file Eligible and moves it to SFL.
     *
     * @throws AuthorizationException
     */
    public function approve(User $reviewer, CustomerEligibilityRequest $request, ?string $note = null): void
    {
        $log = DB::transaction(function () use ($reviewer, $request, $note): CustomerEligibilityLog {
            $current = $this->lockRequest($request, $reviewer);
            $customer = $this->lockCustomer($current->customer);

            if ($customer->eligibility_status !== self::NOT_ELIGIBLE) {
                throw new AuthorizationException('This file is no longer Not Eligible, so the request cannot be approved.');
            }

            $request->forceFill([
                'status' => EligibilityRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $request->customer->forceFill([
                'eligibility_status' => self::ELIGIBLE,
                'eligibility_reason' => null,
                'journey_status' => 'sfl',
            ])->save();

            return $this->writeLog($request->customer, EligibilityLogEvent::RequestApproved, self::NOT_ELIGIBLE, self::ELIGIBLE, $note, $reviewer, $request);
        });

        $this->notify($request->customer, $log, $reviewer);
    }

    /**
     * Rejecting leaves the file Not Eligible; a fresh request may be raised.
     *
     * @throws AuthorizationException
     */
    public function reject(User $reviewer, CustomerEligibilityRequest $request, string $note): void
    {
        $log = DB::transaction(function () use ($reviewer, $request, $note): CustomerEligibilityLog {
            $this->lockRequest($request, $reviewer);

            $request->forceFill([
                'status' => EligibilityRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            return $this->writeLog($request->customer, EligibilityLogEvent::RequestRejected, self::NOT_ELIGIBLE, self::NOT_ELIGIBLE, $note, $reviewer, $request);
        });

        $this->notify($request->customer, $log, $reviewer);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** Re-reads the file under a row lock so two people cannot race a change. */
    private function lockCustomer(Customer $customer): Customer
    {
        return Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws AuthorizationException
     */
    private function lockRequest(CustomerEligibilityRequest $request, User $reviewer): CustomerEligibilityRequest
    {
        if (! self::isReviewer($reviewer)) {
            throw new AuthorizationException('Only an Admin can review an eligibility request.');
        }

        $current = CustomerEligibilityRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if (! $current->isPending()) {
            throw new AuthorizationException('This request has already been reviewed.');
        }

        return $current;
    }

    private function lockedMessage(Customer $customer): string
    {
        return match ($customer->eligibility_status) {
            self::ELIGIBLE => 'This file is Eligible — eligibility can no longer be changed.',
            self::NOT_ELIGIBLE => 'A Not Eligible file can only be made eligible through an Admin request.',
            default => 'You cannot change eligibility on this file.',
        };
    }

    private function writeLog(
        Customer $customer,
        EligibilityLogEvent $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $remarks,
        ?User $user,
        ?CustomerEligibilityRequest $request = null,
    ): CustomerEligibilityLog {
        return $customer->eligibilityLogs()->create([
            'customer_eligibility_request_id' => $request?->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'remarks' => filled($remarks) ? $remarks : null,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * Tells the owner, everyone above them, anyone standing in for them and
     * the Admin what happened.
     * Best-effort: a notification failure must never undo the change.
     */
    private function notify(Customer $customer, CustomerEligibilityLog $log, User $actor): void
    {
        try {
            $access = app(CustomerJourneyAccessService::class);

            $employeeIds = $access->responsibleEmployeeChain($customer)
                ->merge($access->activeBackupIdsFor($customer, JourneyModule::forCustomer($customer)))
                ->filter()
                ->unique();

            $recipients = User::query()
                ->where(fn ($query) => $query
                    ->whereIn('employee_id', $employeeIds)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'Admin')))
                ->whereKeyNot($actor->id)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $summary = match ($log->event) {
                EligibilityLogEvent::StatusChanged => 'changed eligibility from '.self::statusLabel($log->from_status).' to '.self::statusLabel($log->to_status),
                EligibilityLogEvent::RequestRaised => 'requested this Not Eligible file be made Eligible',
                EligibilityLogEvent::RequestApproved => 'approved the eligibility request — the file is now Eligible',
                EligibilityLogEvent::RequestRejected => 'rejected the eligibility request',
                EligibilityLogEvent::Created => 'created the file as '.self::statusLabel($log->to_status),
            };

            Notification::make()
                ->title("Eligibility: {$customer->customer_name}")
                ->body(trim("{$actor->name} {$summary}.".($log->remarks ? " — {$log->remarks}" : '')))
                ->icon('heroicon-o-shield-check')
                ->color($log->event->color())
                ->actions([
                    Action::make('view')
                        ->label('View file')
                        ->url(CustomerResource::getUrl('view', ['record' => $customer]))
                        ->markAsRead(),
                ])
                ->viewData(NotificationCategory::Eligibility->viewData())
                ->sendToDatabase($recipients);
        } catch (Throwable) {
            // A failed notification must never block the eligibility change.
        }
    }
}
