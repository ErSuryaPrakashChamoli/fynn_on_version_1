<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Support\PanMatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One PAN, one file. Wherever a PAN is typed — the lead form, the customer
 * form (new file, lead / assigned-lead conversion, edit), assigned leads,
 * customer edit requests and the lead / customer imports — it is checked
 * here against BOTH the customers and the open (unconverted) leads. A clash
 * may only go ahead once an Admin approves a CustomerPanRequest raised by
 * the person entering it; the approval is spent when the record is saved.
 */
class PanDuplicateService
{
    public const string PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public static function normalize(?string $pan): ?string
    {
        $pan = strtoupper((string) preg_replace('/\s+/', '', (string) $pan));

        return $pan === '' ? null : $pan;
    }

    public static function isWellFormed(?string $pan): bool
    {
        return (bool) preg_match(self::PATTERN, (string) self::normalize($pan));
    }

    /**
     * Customers first, then open leads, newest first. A converted lead is
     * left out: it lives on as the customer it was converted into.
     *
     * @return Collection<int, PanMatch>
     */
    public function matches(?string $pan, ?int $ignoreCustomerId = null, ?int $ignoreLeadId = null): Collection
    {
        $pan = self::normalize($pan);

        if ($pan === null) {
            return collect();
        }

        $customers = Customer::query()
            ->with('employee')
            ->where('pan_number', $pan)
            ->when($ignoreCustomerId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreCustomerId))
            ->latest('id')
            ->get()
            ->map(fn (Customer $customer): PanMatch => PanMatch::fromCustomer($customer));

        $leads = Lead::query()
            ->with('employee')
            ->where('pan_number', $pan)
            ->where('is_converted', false)
            ->when($ignoreLeadId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreLeadId))
            ->latest('id')
            ->get()
            ->map(fn (Lead $lead): PanMatch => PanMatch::fromLead($lead));

        return $customers->concat($leads)->values();
    }

    public function firstMatch(?string $pan, ?int $ignoreCustomerId = null, ?int $ignoreLeadId = null): ?PanMatch
    {
        return $this->matches($pan, $ignoreCustomerId, $ignoreLeadId)->first();
    }

    /**
     * An approved, not yet used request by this employee to use this PAN
     * for a lead or a customer. For a lead, $leadId is the lead being edited
     * (null while adding a new one) and must match the request exactly.
     */
    public function approvedRequest(?Employee $employee, ?string $pan, string $for, ?int $leadId = null): ?CustomerPanRequest
    {
        return $this->requestsBy($employee, $pan, $for, $leadId)
            ?->where('status', CustomerPanRequest::STATUS_APPROVED)
            ->whereNull('used_at')
            ->whereNull('application_id')
            ->latest('id')
            ->first();
    }

    public function pendingRequest(?Employee $employee, ?string $pan, string $for, ?int $leadId = null): ?CustomerPanRequest
    {
        return $this->requestsBy($employee, $pan, $for, $leadId)
            ?->where('status', CustomerPanRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Raises the approval request (snapshotting the requester's reporting
     * line) and tells every Admin.
     *
     * @param  array{reason: string, requested_bank_id?: int|string|null, requested_loan_type?: string|null}  $details
     */
    public function raise(
        Employee $employee,
        PanMatch $match,
        string $for,
        array $details,
        ?int $leadId = null,
        ?int $aiCustomerRecordId = null,
    ): CustomerPanRequest {
        $bank = filled($details['requested_bank_id'] ?? null) ? Bank::find($details['requested_bank_id']) : null;

        $request = CustomerPanRequest::create([
            'customer_id' => $match->isCustomer() ? $match->id : null,
            'matched_lead_id' => $match->isLead() ? $match->id : null,
            'request_for' => $for,
            'ai_customer_record_id' => $aiCustomerRecordId,
            'lead_id' => $leadId,
            'pan_number' => $match->pan,

            'requested_by' => $employee->id,
            'requested_by_emp_id' => $employee->emp_id,
            'requested_by_name' => $employee->emp_name,

            'team_leader_id' => $employee->superviser_id,
            'team_leader_name' => $employee->superviser?->emp_name,

            'manager_id' => $employee->manager_id,
            'manager_name' => $employee->manager?->emp_name,

            'cluster_manager_id' => $employee->cluster_id,
            'cluster_manager_name' => $employee->cluster?->emp_name,

            'business_head_id' => $employee->business_head_id,
            'business_head_name' => $employee->businessHead?->emp_name,

            'requested_bank_id' => $bank?->id,
            'requested_bank_name' => $bank?->bank_name,
            'requested_loan_type' => $details['requested_loan_type'] ?? null,

            'reason' => $details['reason'],
            'status' => CustomerPanRequest::STATUS_PENDING,
        ]);

        $purpose = $for === CustomerPanRequest::FOR_LEAD
            ? 'to add a lead with'
            : 'for a new application with';

        Notification::make()
            ->title('New Duplicate PAN Request')
            ->body("{$employee->emp_name} requested approval {$purpose} PAN {$request->pan_number}, already used by {$match->description()}"
                .($bank ? " — {$bank->bank_name}." : '.'))
            ->warning()
            ->icon('heroicon-o-shield-check')
            ->actions([
                Action::make('review')
                    ->label('Review Request')
                    ->url(CustomerPanRequestResource::getUrl('index'))
                    ->markAsRead(),
            ])
            ->viewData(NotificationCategory::PanRequest->viewData())
            ->sendToDatabase(User::role('Admin')->get());

        return $request;
    }

    /**
     * Spends an approval once the record it was granted for is saved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markUsed(CustomerPanRequest $request, array $attributes = []): void
    {
        $request->forceFill([...$attributes, 'used_at' => now()])->save();
    }

    /**
     * Spends the approval a lead was just saved under (if any). A new lead's
     * request was raised before the lead existed, so it has no lead_id yet.
     */
    public function spendLeadApproval(?Employee $employee, Lead $lead, bool $isNewLead): void
    {
        $request = $this->approvedRequest($employee, $lead->pan_number, CustomerPanRequest::FOR_LEAD, $isNewLead ? null : $lead->id);

        if ($request) {
            $this->markUsed($request, ['lead_id' => $lead->id]);
        }
    }

    /**
     * @return Builder<CustomerPanRequest>|null
     */
    private function requestsBy(?Employee $employee, ?string $pan, string $for, ?int $leadId): ?Builder
    {
        $pan = self::normalize($pan);

        if (! $employee || $pan === null) {
            return null;
        }

        return CustomerPanRequest::query()
            ->where('requested_by', $employee->id)
            ->where('pan_number', $pan)
            ->where('request_for', $for)
            ->when(
                $for === CustomerPanRequest::FOR_LEAD,
                fn (Builder $query): Builder => $leadId === null
                    ? $query->whereNull('lead_id')
                    : $query->where('lead_id', $leadId),
            );
    }
}
