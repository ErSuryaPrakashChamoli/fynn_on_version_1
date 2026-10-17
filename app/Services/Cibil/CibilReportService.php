<?php

namespace App\Services\Cibil;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\CibilAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every CIBIL pull goes through here: it checks the login may pull, records
 * the attempt before calling the bureau (so a crash mid-call still leaves a
 * trace), stores the answer or the failure reason, and writes the activity
 * log. Visibility of the history is decided here too, so the listing, the
 * view page and the "previous reports" panel never disagree. For a lead,
 * the manager's bureau decision is recorded here as well, and moves the
 * lead to Eligible or Not Eligible.
 */
class CibilReportService
{
    public const string PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const string MOBILE_PATTERN = '/^[6-9][0-9]{9}$/';

    public const string PINCODE_PATTERN = '/^[1-9][0-9]{5}$/';

    public function __construct(private CibilClient $client) {}

    /**
     * Pulls a fresh report for the applicant in $data. $subject, the lead or
     * customer file the pull is for, must already have been resolved through
     * the caller's own visibility (App\Support\BureauCheckCandidates) — this
     * never looks one up by id.
     *
     * A bureau error does not throw: the attempt is kept as Failed with the
     * reason, and returned like any other report.
     *
     * @param  array{customer_name: string, pan_number: string, date_of_birth: mixed, mobile_no?: string|null, email?: string|null, gender?: string|null, pincode?: string|null, consent?: bool}  $data
     *
     * @throws AuthorizationException when the login has no CIBIL access
     */
    public function fetch(User $user, array $data, Lead|CustomerAssignment|Customer|null $subject = null): CibilReport
    {
        if (! CibilAccess::canUse($user)) {
            throw new AuthorizationException('You do not have access to fetch CIBIL reports.');
        }

        $details = $this->validated($data);

        $report = CibilReport::query()->create([
            ...$details,
            'customer_id' => $subject instanceof Customer ? $subject->getKey() : null,
            'lead_id' => $subject instanceof Lead ? $subject->getKey() : null,
            'customer_assignment_id' => $subject instanceof CustomerAssignment ? $subject->getKey() : null,
            'requested_by' => $user->getKey(),
            'status' => CibilReportStatus::Pending,
            'consent_given_at' => now(),
        ]);

        try {
            $result = $this->client->fetch(CibilApplicant::fromReport($report));

            $report->forceFill([
                'status' => CibilReportStatus::Success,
                'score' => $result->score,
                'bureau_reference' => $result->bureauReference,
                'response_payload' => $result->payload,
                'fetched_at' => now(),
            ])->save();
        } catch (CibilApiException $exception) {
            $report->forceFill([
                'status' => CibilReportStatus::Failed,
                'error_message' => $exception->getMessage(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $report->forceFill([
                'status' => CibilReportStatus::Failed,
                'error_message' => 'The CIBIL bureau could not be reached. Please try again in a few minutes.',
            ])->save();
        }

        activity()
            ->performedOn($report)
            ->causedBy($user)
            ->event('created')
            ->withProperties([
                'customer_id' => $report->customer_id,
                'lead_id' => $report->lead_id,
                'customer_assignment_id' => $report->customer_assignment_id,
                'customer_name' => $report->customer_name,
                'status' => $report->status->value,
                'score' => $report->score,
            ])
            ->log($report->status === CibilReportStatus::Success
                ? "CIBIL report fetched for {$report->customer_name}"
                : "CIBIL fetch failed for {$report->customer_name}");

        return $report;
    }

    /**
     * Pulls again for the same applicant as an earlier report — the bureau
     * is asked afresh, the earlier report stays as it was. $dateOfBirth fills
     * in (or corrects) the one required detail an older report may lack.
     *
     * @throws AuthorizationException when the login cannot see $previous
     */
    public function refetch(User $user, CibilReport $previous, bool $consent, mixed $dateOfBirth = null): CibilReport
    {
        if (! $this->canView($previous, $user)) {
            throw new AuthorizationException('You cannot fetch a report you do not have access to.');
        }

        return $this->fetch($user, [
            'customer_name' => $previous->customer_name,
            'pan_number' => $previous->pan_number,
            'mobile_no' => $previous->mobile_no,
            'email' => $previous->email,
            'date_of_birth' => filled($dateOfBirth) ? $dateOfBirth : $previous->date_of_birth,
            'gender' => $previous->gender,
            'pincode' => $previous->pincode,
            'consent' => $consent,
        ], $previous->subject());
    }

    /**
     * One row per applicant (PAN) for the listing: their newest fetched
     * report, or their newest attempt when none succeeded, carrying how many
     * times this login can see the PAN was pulled (fetch_count).
     *
     * @param  Builder<CibilReport>  $query
     * @return Builder<CibilReport>
     */
    public function latestPerApplicant(Builder $query, User $user): Builder
    {
        $shownIds = $this->scopeVisible(CibilReport::query(), $user)
            ->selectRaw('COALESCE(MAX(CASE WHEN status = ? THEN id END), MAX(id))', [CibilReportStatus::Success->value])
            ->groupBy('pan_number');

        // Unqualified columns inside the subquery resolve to same_pan.
        $fetchCount = $this->scopeVisible(CibilReport::query()->from('cibil_reports', 'same_pan'), $user)
            ->selectRaw('COUNT(*)')
            ->whereColumn('same_pan.pan_number', 'cibil_reports.pan_number');

        return $query
            ->whereIn('cibil_reports.id', $shownIds)
            ->addSelect(['fetch_count' => $fetchCount]);
    }

    /**
     * The manager may record the bureau decision on a fetched report for a
     * lead that is still waiting for one (its status is "Bureau Check").
     */
    public function canDecide(CibilReport $report, User $user): bool
    {
        if (! CibilAccess::canUse($user) || ! $this->canView($report, $user)) {
            return false;
        }

        if ($report->status !== CibilReportStatus::Success || $report->bureau_decision !== null) {
            return false;
        }

        return $this->leadStatus($report) === CustomerAssignment::BUREAU_CHECK_STATUS;
    }

    /**
     * Records the manager's decision on the report and moves the lead to the
     * matching status. The status change is a follow-up like any other, so
     * it lands in the lead's follow-up log; Not Eligible closes the chase.
     *
     * @throws AuthorizationException when the decision cannot be recorded
     */
    public function decide(User $user, CibilReport $report, BureauDecision $decision, ?string $remarks = null): CibilReport
    {
        if (! $this->canDecide($report, $user)) {
            throw new AuthorizationException('This report is not waiting for a bureau decision.');
        }

        $remarks = filled($remarks) ? trim($remarks) : null;
        $score = $report->score === null ? 'no score' : ($report->score < 300 ? 'no credit history' : "CIBIL score {$report->score}");
        $followUpRemark = "Bureau check: {$decision->label()} ({$score}).".($remarks ? " {$remarks}" : '');

        DB::transaction(function () use ($user, $report, $decision, $remarks, $followUpRemark): void {
            $report->forceFill([
                'bureau_decision' => $decision,
                'decided_by' => $user->getKey(),
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ])->save();

            $closesChase = in_array($decision->leadStatus(), CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES, true);

            if ($report->lead !== null) {
                // Lead::updated mirrors the status change into follow_ups.
                $report->lead->update([
                    'status' => $decision->leadStatus(),
                    'remarks' => $followUpRemark,
                    'next_follow_up_date' => $closesChase ? null : $report->lead->next_follow_up_date,
                ]);
            } elseif ($report->assignedLead !== null) {
                $latest = $report->assignedLead->latestFollowUp();

                FollowUp::query()->create([
                    'customer_id' => $report->assignedLead->customer_id,
                    'ai_customer_record_id' => $report->assignedLead->ai_customer_record_id,
                    'employee_id' => $user->employee?->id,
                    'follow_up_type' => $latest?->follow_up_type ?? 'Call',
                    'status' => $decision->leadStatus(),
                    'remarks' => $followUpRemark,
                    'next_follow_up_date' => $closesChase ? null : $latest?->next_follow_up_date,
                ]);
            }
        });

        activity()
            ->performedOn($report)
            ->causedBy($user)
            ->event('bureau_decision')
            ->withProperties([
                'decision' => $decision->value,
                'lead_id' => $report->lead_id,
                'customer_assignment_id' => $report->customer_assignment_id,
                'customer_name' => $report->customer_name,
                'score' => $report->score,
            ])
            ->log("Bureau decision for {$report->customer_name}: {$decision->label()}");

        return $report;
    }

    /** The current status of the lead the report was fetched for, if any. */
    private function leadStatus(CibilReport $report): ?string
    {
        if ($report->lead !== null) {
            return $report->lead->is_converted ? null : $report->lead->status;
        }

        if ($report->assignedLead !== null) {
            return $report->assignedLead->converted_at === null
                ? $report->assignedLead->latestFollowUpStatus()
                : null;
        }

        return null;
    }

    /**
     * Admin sees every report; anyone else with access sees the reports they
     * fetched themselves; without access, nothing.
     *
     * @param  Builder<CibilReport>  $query
     * @return Builder<CibilReport>
     */
    public function scopeVisible(Builder $query, User $user): Builder
    {
        if (CibilAccess::canManage($user)) {
            return $query;
        }

        if (! CibilAccess::canUse($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('requested_by', $user->getKey());
    }

    public function canView(CibilReport $report, User $user): bool
    {
        if (CibilAccess::canManage($user)) {
            return true;
        }

        return CibilAccess::canUse($user) && $report->isRequestedBy($user);
    }

    /**
     * The applicant's other pulls (same PAN) this login may see, newest first.
     *
     * @return Collection<int, CibilReport>
     */
    public function historyFor(CibilReport $report, User $user): Collection
    {
        return $this->scopeVisible(CibilReport::query(), $user)
            ->with('requester')
            ->where('pan_number', $report->pan_number)
            ->whereKeyNot($report->getKey())
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /** Opening a report is audited, so the Admin can see who looked at whose credit history. */
    public function recordView(CibilReport $report, User $user): void
    {
        activity()
            ->performedOn($report)
            ->causedBy($user)
            ->event('viewed')
            ->withProperties(['customer_name' => $report->customer_name])
            ->log("CIBIL report viewed for {$report->customer_name}");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_name: string, pan_number: string, mobile_no: string|null, email: string|null, date_of_birth: Carbon, gender: string|null, pincode: string|null}
     */
    private function validated(array $data): array
    {
        $data['pan_number'] = Str::upper(trim((string) ($data['pan_number'] ?? '')));
        $data['mobile_no'] = preg_replace('/\D/', '', (string) ($data['mobile_no'] ?? '')) ?: null;

        // The bureau matches an applicant on name, PAN and date of birth; the
        // rest only helps it along.
        $valid = Validator::make($data, [
            'customer_name' => ['required', 'string', 'max:255'],
            'pan_number' => ['required', 'regex:'.self::PAN_PATTERN],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'mobile_no' => ['nullable', 'regex:'.self::MOBILE_PATTERN],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', 'in:'.implode(',', array_keys(CibilReport::GENDERS))],
            'pincode' => ['nullable', 'regex:'.self::PINCODE_PATTERN],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'The customer\'s consent is required before a CIBIL report can be fetched.',
            'date_of_birth.required' => 'Enter the date of birth — the bureau needs it to find the applicant.',
            'pan_number.regex' => 'Enter a valid 10-character PAN, e.g. ABCDE1234F.',
            'mobile_no.regex' => 'Enter a valid 10-digit mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
        ])->validate();

        return [
            'customer_name' => trim($valid['customer_name']),
            'pan_number' => $valid['pan_number'],
            'mobile_no' => $valid['mobile_no'] ?? null,
            'email' => filled($valid['email'] ?? null) ? trim($valid['email']) : null,
            'date_of_birth' => Carbon::parse($valid['date_of_birth']),
            'gender' => $valid['gender'] ?? null,
            'pincode' => $valid['pincode'] ?? null,
        ];
    }
}
