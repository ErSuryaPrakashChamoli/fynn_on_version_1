<?php

namespace App\Services\Cibil;

use App\Enums\BureauDecision;
use App\Enums\CibilConsentMethod;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\CibilAccess;
use App\Support\CibilMask;
use App\Support\CibilSubject;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every credit bureau request (CIBIL or Experian, as the user picked) goes
 * through here. Before anything is sent to the (billable) bureau it checks,
 * in order: the login may make this kind of request, the Admin has requests
 * and that request type switched on, the applicant's details and
 * the customer's consent are complete, and — for a lead or customer file —
 * the PAN is the file's own. Then, under a lock per PAN, bureau and request
 * type, it refuses while an equivalent request is still in flight or one
 * ended with an uncertain outcome nobody has verified, and reuses a report
 * the file already has from the same bureau unless a fresh pull was
 * explicitly asked for. One bureau's report never stands in for the other's. Only then
 * is the attempt recorded (so a crash mid-call still leaves a trace) and
 * the bureau called; its answer, or the reason it failed, is stored and the
 * activity log written.
 *
 * Visibility of the history is decided here too, so the listings, the view
 * page, the file's CIBIL profile and the PDF route never disagree. For a
 * lead, the manager's bureau decision is recorded here as well, and moves
 * the lead to Eligible or Not Eligible.
 */
class CibilReportService
{
    public const string PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const string MOBILE_PATTERN = '/^[6-9][0-9]{9}$/';

    public const string PINCODE_PATTERN = '/^[1-9][0-9]{5}$/';

    /** The activity-log name every CIBIL entry is written under (the audit history reads it). */
    public const string LOG_NAME = 'cibil';

    public function __construct(
        private CibilClient $client,
        private CibilPdfStore $pdfStore,
    ) {}

    /**
     * A full report for the applicant in $data — reused when the file
     * already has one. Kept for the fetch form and older callers.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException when the login may not request full reports
     * @throws CibilRequestRefused when the request cannot be sent right now
     */
    public function fetch(User $user, array $data, Lead|CustomerAssignment|Customer|null $subject = null, CreditBureau $bureau = CreditBureau::Cibil): CibilReport
    {
        return $this->request($user, CibilRequestType::FullReport, $data, $subject, bureau: $bureau)->report;
    }

    /**
     * Asks $bureau for $type about the applicant in $data, made for
     * $subject — the lead or customer file, which must already have been
     * resolved through the caller's own visibility (never looked up by id
     * here). $fresh skips reusing an earlier report; it needs the
     * cibil.refresh permission and a reason, as does sending a request
     * after one whose outcome was uncertain ($data['after_uncertain']).
     *
     * A bureau error does not throw: the attempt is kept as Failed (or
     * Uncertain) with the reason, and returned like any other request.
     *
     * @param  array{customer_name?: string, pan_number?: string, mobile_no?: string|null, gender?: string|null, date_of_birth?: mixed, email?: string|null, pincode?: string|null, consent?: bool, consent_method?: string|null, consent_reference?: string|null, reason?: string|null, after_uncertain?: bool}  $data
     *
     * @throws AuthorizationException when the login may not make this request
     * @throws ValidationException when details or consent are missing or wrong
     * @throws CibilRequestRefused when the request cannot be sent right now
     */
    public function request(User $user, CibilRequestType $type, array $data, Lead|CustomerAssignment|Customer|null $subject = null, bool $fresh = false, CreditBureau $bureau = CreditBureau::Cibil): CibilRequestOutcome
    {
        $afterUncertain = (bool) ($data['after_uncertain'] ?? false);

        if (! CibilAccess::canRequest($user, $type)) {
            throw new AuthorizationException("You do not have access to request a {$bureau->label()} {$type->label()}.");
        }

        if (($fresh || $afterUncertain) && ! CibilAccess::canRefresh($user)) {
            throw new AuthorizationException('You do not have access to request a fresh CIBIL report.');
        }

        $settings = CibilSetting::current();

        match (true) {
            ! $settings->requests_enabled => throw CibilRequestRefused::switchedOff(),
            ! $settings->allows($type) => throw CibilRequestRefused::typeSwitchedOff($type->label()),
            $fresh && ! $settings->fresh_requests_enabled => throw CibilRequestRefused::freshSwitchedOff(),
            default => null,
        };

        $details = $this->validated($data, $subject, $bureau, needsReason: $fresh || $afterUncertain);
        $pan = $details['pan_number'];

        $outcome = $this->underLock($pan, $type, $bureau, function () use ($user, $type, $bureau, $details, $pan, $subject, $fresh, $afterUncertain, $settings): CibilRequestOutcome {
            $this->expireStalePending($pan, $type, $bureau);

            $pending = $this->inFlight($pan, $type, $bureau);

            if ($pending !== null) {
                throw CibilRequestRefused::inProgress($pending);
            }

            $uncertain = $this->unresolvedUncertain($pan, $type, $bureau);

            if ($uncertain !== null && ! $afterUncertain) {
                throw CibilRequestRefused::uncertain($uncertain);
            }

            if (! $fresh && $subject !== null) {
                $reusable = $this->reusableReport($subject, $pan, $type, $bureau, $settings);

                if ($reusable !== null) {
                    return new CibilRequestOutcome($reusable, reused: true);
                }
            }

            return DB::transaction(function () use ($user, $type, $bureau, $details, $subject, $fresh, $uncertain, $settings): CibilRequestOutcome {
                $report = CibilReport::query()->create([
                    'customer_name' => $details['customer_name'],
                    'pan_number' => $details['pan_number'],
                    'mobile_no' => $details['mobile_no'],
                    'email' => $details['email'],
                    'date_of_birth' => $details['date_of_birth'],
                    'gender' => $details['gender'],
                    'pincode' => $details['pincode'],
                    'request_type' => $type,
                    'bureau' => $bureau,
                    'request_reason' => $this->reasonFor($type, $bureau, $details['reason'], $subject, $fresh, $uncertain, $settings),
                    'customer_id' => $subject instanceof Customer ? $subject->getKey() : null,
                    'lead_id' => $subject instanceof Lead ? $subject->getKey() : null,
                    'customer_assignment_id' => $subject instanceof CustomerAssignment ? $subject->getKey() : null,
                    'requested_by' => $user->getKey(),
                    'status' => CibilReportStatus::Pending,
                    'consent_given_at' => now(),
                    'consent_purpose' => $type->consentPurpose($bureau),
                    'consent_method' => $details['consent_method'],
                    'consent_reference' => $details['consent_reference'],
                ]);

                if ($uncertain !== null) {
                    $this->resolveUncertainRequests($report, $user);
                }

                return new CibilRequestOutcome($report);
            });
        });

        if ($outcome->reused) {
            $this->log($outcome->report, $user, 'reused', "Existing {$bureau->label()} {$type->label()} reused for {$outcome->report->customer_name}", [
                'request_type' => $type->value,
                'bureau' => $bureau->value,
                'reused_report_id' => $outcome->report->getKey(),
            ]);

            return $outcome;
        }

        $this->send($outcome->report, $user);

        return $outcome;
    }

    /**
     * Asks the same bureau again for the same applicant as an earlier report
     * — a fresh pull, the earlier report stays as it was. $data carries the consent, the
     * reason, and any detail the earlier report lacks (mobile, gender).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException when the login cannot see $previous or may not pull afresh
     */
    public function refetch(User $user, CibilReport $previous, array $data): CibilRequestOutcome
    {
        if (! $this->canView($previous, $user)) {
            throw new AuthorizationException('You cannot request a report you do not have access to.');
        }

        $type = CibilRequestType::tryFrom((string) ($data['request_type'] ?? '')) ?? $previous->request_type;

        return $this->request($user, $type, [
            'customer_name' => $previous->customer_name,
            'pan_number' => $previous->pan_number,
            'mobile_no' => filled($data['mobile_no'] ?? null) ? $data['mobile_no'] : $previous->mobile_no,
            'gender' => filled($data['gender'] ?? null) ? $data['gender'] : $previous->gender,
            'date_of_birth' => filled($data['date_of_birth'] ?? null) ? $data['date_of_birth'] : $previous->date_of_birth,
            'email' => $previous->email,
            'pincode' => $previous->pincode,
            'consent' => (bool) ($data['consent'] ?? false),
            'consent_method' => $data['consent_method'] ?? null,
            'consent_reference' => $data['consent_reference'] ?? null,
            'reason' => $data['reason'] ?? null,
            'after_uncertain' => (bool) ($data['after_uncertain'] ?? false),
        ], $previous->subject(), fresh: true, bureau: $previous->bureau);
    }

    /**
     * Calls the bureau for a recorded request and stores what came back. A
     * PDF is saved to private storage before the row is completed; a
     * problem saving it never turns a successful (billed) call into a
     * failure.
     */
    private function send(CibilReport $report, User $user): void
    {
        try {
            $result = $this->client->fetch(CibilApplicant::fromReport($report), $report->request_type, $report->bureau);
            $pdf = $report->request_type === CibilRequestType::Pdf ? $this->storePdf($report, $result) : null;

            $report->forceFill([
                'status' => CibilReportStatus::Success,
                'score' => $result->score,
                'bureau_reference' => $result->bureauReference,
                'provider_status_code' => $result->providerStatusCode,
                'provider_message_code' => $result->providerMessageCode,
                'response_payload' => $result->payload,
                'pdf_status' => $pdf?->status,
                'pdf_path' => $pdf?->path,
                'pdf_link' => $pdf?->keepLink ? $result->pdfLink : null,
                'fetched_at' => now(),
                'completed_at' => now(),
            ]);
        } catch (CibilApiException $exception) {
            $report->forceFill([
                'status' => $exception->outcomeUncertain ? CibilReportStatus::Uncertain : CibilReportStatus::Failed,
                'error_message' => $exception->getMessage(),
                'provider_status_code' => $exception->providerStatusCode,
                'provider_message_code' => $exception->providerMessageCode,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->logUnexpected('CIBIL request failed unexpectedly.', $report, $exception);

            // Whether the bureau got the request is unknown, so it is not retried blindly.
            $report->forceFill([
                'status' => CibilReportStatus::Uncertain,
                'error_message' => "Something went wrong while contacting the {$report->bureau->label()} service; the provider may have processed the request. Verify its status before retrying.",
                'completed_at' => now(),
            ]);
        }

        DB::transaction(function () use ($report, $user): void {
            $report->save();

            $name = $report->customer_name;
            $label = $report->request_type->label();
            $bureau = $report->bureau->label();

            $this->log($report, $user, 'created', match ($report->status) {
                CibilReportStatus::Success => match ($report->request_type) {
                    CibilRequestType::Score => "{$bureau} score checked for {$name}",
                    CibilRequestType::FullReport => "{$bureau} report fetched for {$name}",
                    CibilRequestType::Pdf => "{$bureau} PDF report fetched for {$name}",
                },
                CibilReportStatus::Uncertain => "{$bureau} {$label} outcome uncertain for {$name}",
                default => "{$bureau} {$label} failed for {$name}",
            }, [
                'request_type' => $report->request_type->value,
                'bureau' => $report->bureau->value,
                'customer_id' => $report->customer_id,
                'lead_id' => $report->lead_id,
                'customer_assignment_id' => $report->customer_assignment_id,
                'customer_name' => $name,
                'status' => $report->status->value,
                'score' => $report->score,
                'provider_status_code' => $report->provider_status_code,
                'reason' => $report->request_reason,
            ]);
        });
    }

    private function storePdf(CibilReport $report, CibilFetchResult $result): CibilStoredPdf
    {
        try {
            return $this->pdfStore->store($report, $result->pdfBase64, $result->pdfLink);
        } catch (Throwable $exception) {
            $this->logUnexpected('CIBIL PDF could not be saved.', $report, $exception);

            return new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: $result->pdfLink !== null);
        }
    }

    /**
     * Tries the provider's PDF link once more, for a PDF request whose file
     * could not be fetched the first time. No new bureau request is made.
     *
     * @throws AuthorizationException when the login may not open this PDF
     */
    public function retryPdfDownload(User $user, CibilReport $report): CibilReport
    {
        if (! $this->canView($report, $user) || ! CibilAccess::canDownloadPdf($user)) {
            throw new AuthorizationException('You do not have access to this PDF.');
        }

        if (! $this->canRetryPdfDownload($report)) {
            return $report;
        }

        try {
            $stored = $this->pdfStore->download($report, (string) $report->pdf_link);
        } catch (Throwable $exception) {
            $this->logUnexpected('CIBIL PDF could not be saved.', $report, $exception);
            $stored = new CibilStoredPdf(CibilPdfStatus::DownloadFailed, keepLink: true);
        }

        DB::transaction(function () use ($report, $user, $stored): void {
            $report->forceFill([
                'pdf_status' => $stored->status,
                'pdf_path' => $stored->path,
                'pdf_link' => $stored->keepLink ? $report->pdf_link : null,
            ])->save();

            $this->log($report, $user, 'pdf_retried', "CIBIL PDF download retried for {$report->customer_name}", [
                'pdf_status' => $stored->status->value,
            ]);
        });

        return $report;
    }

    public function canRetryPdfDownload(CibilReport $report): bool
    {
        return $report->status === CibilReportStatus::Success
            && $report->pdf_status === CibilPdfStatus::DownloadFailed
            && filled($report->pdf_link);
    }

    /**
     * Records that someone verified a request whose outcome was uncertain
     * (e.g. against the provider's dashboard), so a new request for that
     * PAN may go ahead. The request itself keeps its status.
     *
     * @throws AuthorizationException when the login is not the Admin
     */
    public function resolveUncertain(User $user, CibilReport $report, string $note): CibilReport
    {
        if (! CibilAccess::canManage($user)) {
            throw new AuthorizationException('Only the Admin can verify an uncertain CIBIL request.');
        }

        if (! $report->isUnresolvedUncertain()) {
            return $report;
        }

        DB::transaction(function () use ($user, $report, $note): void {
            $report->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $user->getKey(),
                'resolution_note' => Str::limit(trim($note), 500, ''),
            ])->save();

            $this->log($report, $user, 'uncertain_resolved', "Uncertain CIBIL request verified for {$report->customer_name}", [
                'note' => $report->resolution_note,
            ]);
        });

        return $report;
    }

    /**
     * Sending a request after an uncertain one, with the permission and a
     * reason, counts as having verified the uncertain one(s).
     */
    private function resolveUncertainRequests(CibilReport $report, User $user): void
    {
        CibilReport::query()
            ->where('pan_number', $report->pan_number)
            ->where('request_type', $report->request_type)
            ->where('bureau', $report->bureau)
            ->where('status', CibilReportStatus::Uncertain)
            ->whereNull('resolved_at')
            ->whereKeyNot($report->getKey())
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $user->getKey(),
                'resolution_note' => "Verified before request #{$report->getKey()} was sent.",
            ]);
    }

    /**
     * A converted lead's reports belong to the new customer file too: the
     * same rows gain the customer link (nothing is copied), provided the
     * report was for the PAN the file was created with. This follows the
     * conversion itself — records are never matched up by PAN alone.
     *
     * @param  list<int>  $assignmentIds  assigned-lead rows that were converted
     */
    public function linkConvertedLead(Customer $customer, ?int $leadId, array $assignmentIds = [], ?User $user = null): int
    {
        if ($leadId === null && $assignmentIds === []) {
            return 0;
        }

        $pan = Str::upper(trim((string) $customer->pan_number));

        if ($pan === '') {
            return 0;
        }

        $reports = CibilReport::query()
            ->whereNull('customer_id')
            ->where('pan_number', $pan)
            ->where(fn (Builder $query): Builder => $query
                ->when($leadId !== null, fn (Builder $query): Builder => $query->orWhere('lead_id', $leadId))
                ->when($assignmentIds !== [], fn (Builder $query): Builder => $query->orWhereIn('customer_assignment_id', $assignmentIds)))
            ->get();

        foreach ($reports as $report) {
            $report->forceFill(['customer_id' => $customer->getKey()])->save();

            $this->log($report, $user, 'linked', "CIBIL report linked to customer file of {$report->customer_name}", [
                'customer_id' => $customer->getKey(),
                'lead_id' => $report->lead_id,
                'customer_assignment_id' => $report->customer_assignment_id,
            ]);
        }

        return $reports->count();
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
        $score = $report->score === null ? 'no score' : ($report->score < 300 ? 'no credit history' : "{$report->bureau->label()} score {$report->score}");
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

        $this->log($report, $user, 'bureau_decision', "Bureau decision for {$report->customer_name}: {$decision->label()}", [
            'decision' => $decision->value,
            'lead_id' => $report->lead_id,
            'customer_assignment_id' => $report->customer_assignment_id,
            'customer_name' => $report->customer_name,
            'score' => $report->score,
        ]);

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
     * Admin sees every report. Anyone else with CIBIL access sees the
     * reports they requested plus those made for the leads and customer
     * files they can see (each listing's own scope decides that) — so a
     * manager finds the report a caller pulled on their team's file instead
     * of paying for another. Without CIBIL access, nothing.
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

        // The listings read the signed-in user, so only they get the files' reach.
        if (auth()->id() !== $user->getKey()) {
            return $query->where('requested_by', $user->getKey());
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->where('requested_by', $user->getKey())
            ->orWhereIn('customer_id', CibilSubject::visibleIds(Customer::class))
            ->orWhereIn('lead_id', CibilSubject::visibleIds(Lead::class))
            ->orWhereIn('customer_assignment_id', CibilSubject::visibleIds(CustomerAssignment::class)));
    }

    public function canView(CibilReport $report, User $user): bool
    {
        if (CibilAccess::canManage($user)) {
            return true;
        }

        if (! CibilAccess::canUse($user)) {
            return false;
        }

        return $report->isRequestedBy($user)
            || $this->scopeVisible(CibilReport::query()->whereKey($report->getKey()), $user)->exists();
    }

    public function canViewFullReport(CibilReport $report, User $user): bool
    {
        return CibilAccess::canViewFullReport($user) && $this->canView($report, $user);
    }

    public function canDownloadPdf(CibilReport $report, User $user): bool
    {
        return $report->hasPdf() && CibilAccess::canDownloadPdf($user) && $this->canView($report, $user);
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

    /**
     * The newest request for this PAN, type and bureau that ended uncertain
     * and that nobody has verified yet, if any.
     */
    public function unresolvedUncertain(string $pan, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): ?CibilReport
    {
        return CibilReport::query()
            ->where('pan_number', Str::upper(trim($pan)))
            ->where('request_type', $type)
            ->where('bureau', $bureau)
            ->where('status', CibilReportStatus::Uncertain)
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();
    }

    /**
     * Requests still "pending" long after the provider's timeout: the app
     * stopped waiting (e.g. the process was killed), so the provider may or
     * may not have answered. They become uncertain. Without arguments, every
     * PAN is swept (for the dashboard).
     */
    public function expireStalePending(?string $pan = null, ?CibilRequestType $type = null, ?CreditBureau $bureau = null): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('cibil.pending_stale_after_minutes', 5)));

        return CibilReport::query()
            ->where('status', CibilReportStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->when($pan !== null, fn (Builder $query): Builder => $query->where('pan_number', $pan))
            ->when($type !== null, fn (Builder $query): Builder => $query->where('request_type', $type))
            ->when($bureau !== null, fn (Builder $query): Builder => $query->where('bureau', $bureau))
            ->update([
                'status' => CibilReportStatus::Uncertain->value,
                'error_message' => 'The request did not complete — the app stopped waiting for the provider. Its outcome is uncertain; verify its status before retrying.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Opening a report is audited, so the Admin can see who looked at whose credit history. */
    public function recordView(CibilReport $report, User $user): void
    {
        $this->log($report, $user, 'viewed', "CIBIL report viewed for {$report->customer_name}", [
            'customer_name' => $report->customer_name,
        ]);
    }

    public function recordPdfAccess(CibilReport $report, User $user): void
    {
        $this->log($report, $user, 'pdf_downloaded', "CIBIL PDF opened for {$report->customer_name}", [
            'customer_name' => $report->customer_name,
        ]);
    }

    private function inFlight(string $pan, CibilRequestType $type, CreditBureau $bureau): ?CibilReport
    {
        return CibilReport::query()
            ->where('pan_number', $pan)
            ->where('request_type', $type)
            ->where('bureau', $bureau)
            ->where('status', CibilReportStatus::Pending)
            ->latest('id')
            ->first();
    }

    /**
     * The file's newest fetched report from the same bureau that already
     * answers $type for this PAN, within the Admin's reuse window when one
     * is set.
     */
    private function reusableReport(Lead|CustomerAssignment|Customer $subject, string $pan, CibilRequestType $type, CreditBureau $bureau, CibilSetting $settings): ?CibilReport
    {
        return CibilReport::query()
            ->where(CibilSubject::foreignKey($subject), $subject->getKey())
            ->where('pan_number', $pan)
            ->where('bureau', $bureau)
            ->where('status', CibilReportStatus::Success)
            ->whereIn('request_type', $type->satisfiedBy())
            ->when($type === CibilRequestType::Pdf, fn (Builder $query): Builder => $query->where('pdf_status', CibilPdfStatus::Available))
            ->when($settings->reuseCutoff(), fn (Builder $query, Carbon $cutoff): Builder => $query->where('fetched_at', '>=', $cutoff))
            ->latest('fetched_at')
            ->latest('id')
            ->first();
    }

    /** Why this request was sent rather than an earlier report reused. */
    private function reasonFor(CibilRequestType $type, CreditBureau $bureau, ?string $given, Lead|CustomerAssignment|Customer|null $subject, bool $fresh, ?CibilReport $uncertain, CibilSetting $settings): string
    {
        if ($uncertain !== null) {
            return Str::limit("Sent after uncertain request #{$uncertain->getKey()} was verified: {$given}", 500, '');
        }

        if ($fresh) {
            return Str::limit("Fresh pull requested: {$given}", 500, '');
        }

        if ($subject === null) {
            return 'No lead or customer file linked, so no earlier report could be reused.';
        }

        $older = CibilReport::query()
            ->where(CibilSubject::foreignKey($subject), $subject->getKey())
            ->where('bureau', $bureau)
            ->where('status', CibilReportStatus::Success)
            ->whereIn('request_type', $type->satisfiedBy())
            ->latest('fetched_at')
            ->first();

        $label = "{$bureau->label()} {$type->label()}";

        return $older !== null && $settings->reuse_window_days
            ? "The file's last {$label} ({$older->fetched_at?->format('d M Y')}) is older than the {$settings->reuse_window_days}-day reuse window."
            : "No earlier {$label} on this file.";
    }

    /**
     * One request at a time per PAN, bureau and type: the check-then-record
     * step runs under a lock, and the pending row it leaves stops the next.
     *
     * @param  Closure(): CibilRequestOutcome  $callback
     */
    private function underLock(string $pan, CibilRequestType $type, CreditBureau $bureau, Closure $callback): CibilRequestOutcome
    {
        $lock = Cache::lock('cibil-request:'.hash('sha256', $pan.'|'.$bureau->value.'|'.$type->value), 30);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw CibilRequestRefused::inProgress();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(CibilReport $report, ?User $user, string $event, string $description, array $properties): void
    {
        activity(self::LOG_NAME)
            ->performedOn($report)
            ->causedBy($user)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }

    /** Logs what went wrong without the report, the applicant's details or any link. */
    private function logUnexpected(string $message, CibilReport $report, Throwable $exception): void
    {
        Log::error($message, [
            'cibil_report_id' => $report->getKey(),
            'exception' => $exception::class,
            'message' => CibilMask::scrub($exception->getMessage(), 300),
            'at' => basename($exception->getFile()).':'.$exception->getLine(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_name: string, pan_number: string, mobile_no: string, email: string|null, date_of_birth: Carbon|null, gender: string|null, pincode: string|null, consent_method: CibilConsentMethod, consent_reference: string|null, reason: string|null}
     */
    private function validated(array $data, Lead|CustomerAssignment|Customer|null $subject, CreditBureau $bureau, bool $needsReason): array
    {
        $data['pan_number'] = Str::upper(trim((string) ($data['pan_number'] ?? '')));
        $mobile = preg_replace('/\D/', '', (string) ($data['mobile_no'] ?? '')) ?: null;
        $data['mobile_no'] = $mobile !== null && strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;

        // Surepass matches the applicant on name, PAN and mobile (CIBIL also
        // on gender); the rest is kept for the record.
        $validator = Validator::make($data, [
            'customer_name' => ['required', 'string', 'max:255'],
            'pan_number' => ['required', 'regex:'.self::PAN_PATTERN],
            'mobile_no' => ['required', 'regex:'.self::MOBILE_PATTERN],
            'gender' => [Rule::requiredIf($bureau === CreditBureau::Cibil), 'nullable', Rule::in(array_keys(CibilReport::GENDERS))],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'pincode' => ['nullable', 'regex:'.self::PINCODE_PATTERN],
            'consent' => ['accepted'],
            'consent_method' => ['required', Rule::enum(CibilConsentMethod::class)],
            'consent_reference' => ['nullable', 'string', 'max:255'],
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:5', 'max:400'],
        ], [
            'customer_name.required' => 'Required customer details are missing: enter the name as on PAN.',
            'pan_number.required' => 'Required customer details are missing: enter the PAN.',
            'mobile_no.required' => 'Required customer details are missing: enter the mobile number.',
            'gender.required' => 'Required customer details are missing: choose the gender.',
            'pan_number.regex' => 'Enter a valid 10-character PAN, e.g. ABCDE1234F.',
            'mobile_no.regex' => 'Enter a valid 10-digit mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'consent.accepted' => 'Customer consent is required before a credit bureau request can be made.',
            'consent_method.required' => 'Record how the customer gave their consent.',
            'reason.required' => 'Give the reason a fresh request is needed.',
        ]);

        // The request must be for the file's own applicant.
        $validator->after(function ($validator) use ($data, $subject): void {
            $filePan = $subject !== null ? CibilSubject::details($subject)['pan_number'] : null;

            if ($filePan !== null && $data['pan_number'] !== '' && $data['pan_number'] !== $filePan) {
                $validator->errors()->add('pan_number', 'This PAN does not match the PAN on the '.Str::lower(CibilSubject::typeLabel($subject)).' file. A report can only be requested for the file\'s own applicant.');
            }
        });

        $valid = $validator->validate();

        return [
            'customer_name' => trim($valid['customer_name']),
            'pan_number' => $valid['pan_number'],
            'mobile_no' => $valid['mobile_no'],
            'email' => filled($valid['email'] ?? null) ? trim($valid['email']) : null,
            'date_of_birth' => filled($valid['date_of_birth'] ?? null) ? Carbon::parse($valid['date_of_birth']) : null,
            'gender' => filled($valid['gender'] ?? null) ? $valid['gender'] : null,
            'pincode' => $valid['pincode'] ?? null,
            'consent_method' => CibilConsentMethod::from($valid['consent_method']),
            'consent_reference' => filled($valid['consent_reference'] ?? null) ? trim($valid['consent_reference']) : null,
            'reason' => filled($valid['reason'] ?? null) ? trim($valid['reason']) : null,
        ];
    }
}
