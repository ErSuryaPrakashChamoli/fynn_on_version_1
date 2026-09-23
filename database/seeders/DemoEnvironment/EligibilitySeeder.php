<?php

namespace Database\Seeders\DemoEnvironment;

use App\Enums\EligibilityLogEvent;
use App\Enums\EligibilityRequestStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The eligibility module (Request → Eligibility Requests, and the
 * Eligibility section on every customer file): a creation line for every
 * file, consent-pending files that were later decided, and Not Eligible
 * files whose requests the Admin approved, rejected or has yet to review.
 *
 * The persona caller is always left with something to show live: requests
 * waiting for the Admin, a Not Eligible file with no request yet, and a
 * Consent Pending file they can change.
 */
class EligibilitySeeder extends Seeder
{
    private const CONSENT_REMARKS = [
        'Customer gave consent on call, documents to follow.',
        'Consent received on WhatsApp after explaining the offer.',
        'Customer agreed after the EMI was explained.',
    ];

    private const NOT_ELIGIBLE_REMARKS = [
        'CIBIL pulled after consent — score below cut-off.',
        'Customer shared bank statement, bounces in the last 3 months.',
        'Employer not on the listed company catalogue.',
    ];

    private const REQUEST_REASONS = [
        'Salary revised this month — new payslip shared, now above the cut-off.',
        'Employer has been added to the listed company catalogue.',
        'Customer has closed the overdue card; updated CIBIL report attached.',
        'Residence proof received (registered rent agreement).',
        'Bounces were technical — bank letter confirming the reversal shared.',
    ];

    private const APPROVAL_NOTES = [
        'Verified the revised payslip. Approved.',
        'Company catalogue updated — go ahead with SFL.',
        'Updated CIBIL checked, fine to proceed.',
    ];

    private const REJECTION_NOTES = [
        'CIBIL still below the cut-off — re-apply after 3 months.',
        'Salary still under the minimum for this product.',
        'Location not serviceable yet.',
    ];

    private DemoWorld $world;

    private int $adminUserId;

    /** @var array<int, array<string, mixed>> */
    private array $logs = [];

    public function run(DemoWorld $world): void
    {
        $this->world = $world;
        $this->adminUserId = $world->personaUserIds['admin'];

        $personaCaller = $world->personaEmployeeIds['caller'] ?? null;
        $this->ensureConsentPendingFor($personaCaller);

        $customers = DB::table('customers')
            ->orderBy('id')
            ->get(['id', 'assign_to', 'eligibility_status', 'created_at']);

        $personaNotEligible = $customers
            ->where('assign_to', $personaCaller)
            ->where('eligibility_status', 'not_eligible')
            ->pluck('id')
            ->values();

        // Two waiting requests, and at least one file left free to request live.
        $personaPendingIds = $personaNotEligible->take(min(2, max(0, $personaNotEligible->count() - 1)))->all();
        $personaUntouchedIds = $personaNotEligible->diff($personaPendingIds)->all();

        foreach ($customers as $customer) {
            $owner = $this->world->userIdFor($customer->assign_to) ?? $this->adminUserId;
            $created = Carbon::parse($customer->created_at);

            match ($customer->eligibility_status) {
                'eligible' => $this->eligible($customer->id, $owner, $created),
                'not_eligible' => $this->notEligible(
                    $customer->id,
                    $owner,
                    $created,
                    forcePending: in_array($customer->id, $personaPendingIds, true),
                    leaveOpen: in_array($customer->id, $personaUntouchedIds, true),
                ),
                default => $this->log($customer->id, EligibilityLogEvent::Created, null, $customer->eligibility_status, null, $owner, $created),
            };
        }

        $this->world->insert('customer_eligibility_logs', $this->logs);
    }

    /**
     * The journey seeder only makes Consent Pending by chance; the persona
     * caller needs one to show "change any time", so their newest Not
     * Eligible file is still waiting on consent instead.
     */
    private function ensureConsentPendingFor(?int $employeeId): void
    {
        if ($employeeId === null) {
            return;
        }

        $files = DB::table('customers')->where('assign_to', $employeeId);

        if ((clone $files)->where('eligibility_status', 'consent_pending')->exists()) {
            return;
        }

        $newestNotEligible = (clone $files)->where('eligibility_status', 'not_eligible')->latest('created_at')->value('id');

        if ($newestNotEligible) {
            DB::table('customers')->where('id', $newestNotEligible)->update([
                'eligibility_status' => 'consent_pending',
                'eligibility_reason' => null,
            ]);
        }
    }

    private function eligible(int $customerId, int $owner, Carbon $created): void
    {
        if ($this->world->chance(0.08)) {
            // Came in Not Eligible; the Admin approved a request.
            $this->log($customerId, EligibilityLogEvent::Created, null, 'not_eligible', null, $owner, $created);
            $this->request($customerId, $owner, $created, EligibilityRequestStatus::Approved);

            return;
        }

        if ($this->world->chance(0.15)) {
            $this->log($customerId, EligibilityLogEvent::Created, null, 'consent_pending', null, $owner, $created);
            $this->log($customerId, EligibilityLogEvent::StatusChanged, 'consent_pending', 'eligible', $this->world->pick(self::CONSENT_REMARKS), $owner, $this->after($created, 4, 30));

            return;
        }

        $this->log($customerId, EligibilityLogEvent::Created, null, 'eligible', null, $owner, $created);
    }

    private function notEligible(int $customerId, int $owner, Carbon $created, bool $forcePending, bool $leaveOpen): void
    {
        $decidedAt = $created;

        if ($this->world->chance(0.2)) {
            $this->log($customerId, EligibilityLogEvent::Created, null, 'consent_pending', null, $owner, $created);
            $decidedAt = $this->after($created, 4, 30);
            $this->log($customerId, EligibilityLogEvent::StatusChanged, 'consent_pending', 'not_eligible', $this->world->pick(self::NOT_ELIGIBLE_REMARKS), $owner, $decidedAt);
        } else {
            $this->log($customerId, EligibilityLogEvent::Created, null, 'not_eligible', null, $owner, $created);
        }

        if ($leaveOpen) {
            return;
        }

        if ($forcePending) {
            $this->request($customerId, $owner, $this->recent($decidedAt), EligibilityRequestStatus::Pending);

            return;
        }

        if ($this->world->chance(0.18)) {
            $decidedAt = $this->request($customerId, $owner, $decidedAt, EligibilityRequestStatus::Rejected);
        }

        if ($this->world->chance(0.12)) {
            $this->request($customerId, $owner, $this->recent($decidedAt), EligibilityRequestStatus::Pending);
        }
    }

    /**
     * Writes one request with its log lines; returns when it was last touched.
     */
    private function request(int $customerId, int $requester, Carbon $after, EligibilityRequestStatus $status): Carbon
    {
        $raisedAt = $status === EligibilityRequestStatus::Pending ? $after : $this->after($after, 20, 72);
        $reviewedAt = $status === EligibilityRequestStatus::Pending ? null : $this->after($raisedAt, 2, 48);
        $reason = $this->world->pick(self::REQUEST_REASONS);
        $note = match ($status) {
            EligibilityRequestStatus::Approved => $this->world->pick(self::APPROVAL_NOTES),
            EligibilityRequestStatus::Rejected => $this->world->pick(self::REJECTION_NOTES),
            EligibilityRequestStatus::Pending => null,
        };

        $requestId = DB::table('customer_eligibility_requests')->insertGetId([
            'customer_id' => $customerId,
            'requested_by' => $requester,
            'reason' => $reason,
            'status' => $status->value,
            'reviewed_by' => $reviewedAt ? $this->adminUserId : null,
            'reviewed_at' => $reviewedAt,
            'review_note' => $note,
            'created_at' => $raisedAt,
            'updated_at' => $reviewedAt ?? $raisedAt,
        ]);

        $this->log($customerId, EligibilityLogEvent::RequestRaised, 'not_eligible', 'eligible', $reason, $requester, $raisedAt, $requestId);

        if ($status === EligibilityRequestStatus::Approved) {
            $this->log($customerId, EligibilityLogEvent::RequestApproved, 'not_eligible', 'eligible', $note, $this->adminUserId, $reviewedAt, $requestId);
        }

        if ($status === EligibilityRequestStatus::Rejected) {
            $this->log($customerId, EligibilityLogEvent::RequestRejected, 'not_eligible', 'not_eligible', $note, $this->adminUserId, $reviewedAt, $requestId);
        }

        return $reviewedAt ?? $raisedAt;
    }

    private function log(int $customerId, EligibilityLogEvent $event, ?string $from, ?string $to, ?string $remarks, ?int $userId, Carbon $at, ?int $requestId = null): void
    {
        $this->logs[] = [
            'customer_id' => $customerId,
            'customer_eligibility_request_id' => $requestId,
            'event' => $event->value,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
            'user_id' => $userId,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /** A moment $minHours–$maxHours after $from, never in the future. */
    private function after(Carbon $from, int $minHours, int $maxHours): Carbon
    {
        return $from->copy()->addMinutes(mt_rand($minHours * 60, $maxHours * 60))->min($this->world->now);
    }

    /** Within the last few days (and after $from), so waiting requests look current. */
    private function recent(Carbon $from): Carbon
    {
        $recent = $this->world->now->copy()->subMinutes(mt_rand(60, 4 * 24 * 60));

        return $recent->max($from->copy()->addHour())->min($this->world->now);
    }
}
