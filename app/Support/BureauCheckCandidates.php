<?php

namespace App\Support;

use App\Enums\CibilReportStatus;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * Who a CIBIL report can be fetched for, as the CIBIL fetch form offers them:
 * leads (My Leads and Assigned Leads) marked "Bureau Check", and customer
 * files — those without a fetched report first, any of the user's files by
 * search. Everything is read through the owning listing's own scope, so a
 * crafted post can never attach a report to a lead or file the user cannot
 * see.
 *
 * A lead is picked by a key that says which listing it comes from:
 * "lead:12" (My Leads) or "assigned:5" (Assigned Leads).
 */
final class BureauCheckCandidates
{
    public const string MY_LEAD = 'lead';

    public const string ASSIGNED_LEAD = 'assigned';

    /**
     * My Leads waiting for a bureau check (converted leads are already out
     * of the lead listing's scope).
     *
     * @return Builder<Lead>
     */
    public static function leads(): Builder
    {
        return LeadResource::getEloquentQuery()
            ->where('status', CustomerAssignment::BUREAU_CHECK_STATUS);
    }

    /**
     * Assigned leads whose latest follow-up is "Bureau Check".
     *
     * @return Builder<CustomerAssignment>
     */
    public static function assignedLeads(): Builder
    {
        return AssignedLeadResource::getEloquentQuery()
            ->whereNull('customer_assignments.converted_at')
            ->whereLatestFollowUpStatus([CustomerAssignment::BUREAU_CHECK_STATUS]);
    }

    /**
     * Both kinds of lead, grouped by the listing they come from.
     *
     * @return array<string, array<string, string>>
     */
    public static function leadOptions(): array
    {
        $myLeads = self::leads()
            ->orderBy('customer_name')
            ->get()
            ->mapWithKeys(fn (Lead $lead): array => [self::MY_LEAD.':'.$lead->id => self::leadLabel($lead)])
            ->all();

        $assignedLeads = self::assignedLeads()
            ->get()
            ->mapWithKeys(fn (CustomerAssignment $lead): array => [self::ASSIGNED_LEAD.':'.$lead->id => self::leadLabel($lead)])
            ->sort()
            ->all();

        return array_filter([
            'My Leads' => $myLeads,
            'Assigned Leads' => $assignedLeads,
        ]);
    }

    /**
     * The lead behind a picker key, if it is still waiting for a bureau
     * check and the user can see it.
     */
    public static function resolveLead(mixed $key): Lead|CustomerAssignment|null
    {
        [$listing, $id] = array_pad(explode(':', (string) $key, 2), 2, null);

        if (! ctype_digit((string) $id)) {
            return null;
        }

        return match ($listing) {
            self::MY_LEAD => self::leads()->find((int) $id),
            self::ASSIGNED_LEAD => self::assignedLeads()->find((int) $id),
            default => null,
        };
    }

    public static function leadLabel(Lead|CustomerAssignment $lead): string
    {
        if ($lead instanceof Lead) {
            return self::label($lead->customer_name, $lead->pan_number ?: $lead->mobile_no);
        }

        return self::label(
            $lead->display_name,
            $lead->prospectValue('pan_number') ?: $lead->prospectValue('mobile_number'),
        );
    }

    /**
     * The details a lead already holds, for pre-filling the fetch form.
     *
     * @return array{customer_name: ?string, pan_number: ?string, mobile_no: ?string, email: ?string}
     */
    public static function leadDetails(Lead|CustomerAssignment $lead): array
    {
        if ($lead instanceof Lead) {
            return [
                'customer_name' => $lead->customer_name,
                'pan_number' => filled($lead->pan_number) ? Str::upper($lead->pan_number) : null,
                'mobile_no' => $lead->mobile_no,
                'email' => $lead->email,
            ];
        }

        return [
            'customer_name' => $lead->prospectValue('customer_name'),
            'pan_number' => $lead->prospectValue('pan_number'),
            'mobile_no' => $lead->prospectValue('mobile_number'),
            'email' => $lead->prospectValue('email'),
        ];
    }

    /**
     * The user's customer files nobody has fetched a report for yet — none
     * linked to the file and none for its PAN — newest first.
     *
     * @return array<int, string>
     */
    public static function pendingCustomerOptions(): array
    {
        return CustomerResource::getEloquentQuery()
            ->whereNotExists(fn (QueryBuilder $reports): QueryBuilder => $reports
                ->selectRaw('1')
                ->from('cibil_reports')
                ->where('cibil_reports.status', CibilReportStatus::Success->value)
                ->where(fn (QueryBuilder $match): QueryBuilder => $match
                    ->whereColumn('cibil_reports.customer_id', 'customers.id')
                    ->orWhereColumn('cibil_reports.pan_number', 'customers.pan_number')))
            ->latest('customers.id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::customerLabel($customer)])
            ->all();
    }

    /**
     * Any of the user's customer files, by name, mobile, PAN or application
     * number.
     *
     * @return array<int, string>
     */
    public static function searchCustomers(string $search): array
    {
        return CustomerResource::getEloquentQuery()
            ->where(fn (Builder $query): Builder => $query
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('mobile_no', 'like', "%{$search}%")
                ->orWhere('pan_number', 'like', "%{$search}%")
                ->orWhere('application_no', 'like', "%{$search}%"))
            ->orderBy('customer_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::customerLabel($customer)])
            ->all();
    }

    public static function resolveCustomer(mixed $id): ?Customer
    {
        if (blank($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        $customer = CustomerResource::getEloquentQuery()->find((int) $id);

        return $customer instanceof Customer ? $customer : null;
    }

    public static function customerLabel(Customer $customer): string
    {
        return self::label($customer->customer_name, $customer->pan_number ?: $customer->mobile_no);
    }

    private static function label(?string $name, ?string $detail): string
    {
        return trim(($name ?: 'Unnamed').($detail ? ' · '.$detail : ''));
    }
}
