<?php

namespace App\Support;

use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The file a CIBIL request is made for — a My Leads lead, an assigned lead
 * or a customer file — seen the way the CIBIL module needs it: which
 * cibil_reports column links to it, what it already holds for the bureau,
 * and whether the signed-in user can see it through its own listing (the
 * listing's scope is the single source of who sees which file).
 */
final class CibilSubject
{
    /**
     * @return 'customer_id'|'lead_id'|'customer_assignment_id'
     */
    public static function foreignKey(Lead|CustomerAssignment|Customer $subject): string
    {
        return match (true) {
            $subject instanceof Lead => 'lead_id',
            $subject instanceof CustomerAssignment => 'customer_assignment_id',
            default => 'customer_id',
        };
    }

    public static function typeLabel(Lead|CustomerAssignment|Customer $subject): string
    {
        return match (true) {
            $subject instanceof Lead => 'Lead',
            $subject instanceof CustomerAssignment => 'Assigned lead',
            default => 'Customer',
        };
    }

    /**
     * What the file already holds for the bureau. Gender and date of birth
     * are not kept on leads or customer files, so they always come from the
     * person making the request.
     *
     * @return array{customer_name: ?string, pan_number: ?string, mobile_no: ?string, email: ?string}
     */
    public static function details(Lead|CustomerAssignment|Customer $subject): array
    {
        $details = $subject instanceof Customer
            ? [
                'customer_name' => $subject->customer_name,
                'pan_number' => $subject->pan_number,
                'mobile_no' => $subject->mobile_no,
                'email' => $subject->email,
            ]
            : BureauCheckCandidates::leadDetails($subject);

        $details['pan_number'] = filled($details['pan_number']) ? Str::upper(trim((string) $details['pan_number'])) : null;
        $details['mobile_no'] = filled($details['mobile_no']) ? (preg_replace('/\D/', '', (string) $details['mobile_no']) ?: null) : null;

        // Keep the last ten digits, so "+91 98765 43210" matches the bureau's format.
        if ($details['mobile_no'] !== null && strlen($details['mobile_no']) > 10) {
            $details['mobile_no'] = substr($details['mobile_no'], -10);
        }

        return $details;
    }

    /** Whether the signed-in user can see the file through its own listing. */
    public static function isVisible(Lead|CustomerAssignment|Customer $subject): bool
    {
        return self::visibleQuery($subject::class)->whereKey($subject->getKey())->exists();
    }

    /**
     * Ids of the files of this kind the signed-in user can see, for use as a
     * subquery.
     *
     * @param  class-string<Lead|CustomerAssignment|Customer>  $class
     * @return Builder<Lead|CustomerAssignment|Customer>
     */
    public static function visibleIds(string $class): Builder
    {
        $query = self::visibleQuery($class);

        return $query->select($query->getModel()->getQualifiedKeyName())->setEagerLoads([]);
    }

    /**
     * @param  class-string<Lead|CustomerAssignment|Customer>  $class
     * @return Builder<Lead|CustomerAssignment|Customer>
     */
    private static function visibleQuery(string $class): Builder
    {
        return match ($class) {
            Lead::class => LeadResource::getEloquentQuery(),
            CustomerAssignment::class => AssignedLeadResource::getEloquentQuery(),
            default => CustomerResource::getEloquentQuery(),
        };
    }
}
