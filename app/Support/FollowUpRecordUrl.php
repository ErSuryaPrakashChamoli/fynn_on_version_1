<?php

namespace App\Support;

use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\FollowUp;

/**
 * Where "open the record" goes for a follow-up: the Customer, AI-extracted
 * customer record or raw Lead it is tied to.
 */
class FollowUpRecordUrl
{
    public static function for(FollowUp $followUp): ?string
    {
        if ($followUp->customer_id) {
            return CustomerResource::getUrl('view', ['record' => $followUp->customer_id]);
        }

        if ($followUp->ai_customer_record_id) {
            return AiCustomerRecordResource::getUrl('view', ['record' => $followUp->ai_customer_record_id]);
        }

        if ($followUp->lead_id) {
            // A converted lead drops out of the Leads screen (404) — open
            // the customer it became instead.
            if ($followUp->lead?->is_converted && $followUp->lead->converted_customer_id) {
                return CustomerResource::getUrl('view', ['record' => $followUp->lead->converted_customer_id]);
            }

            return LeadResource::getEloquentQuery()->whereKey($followUp->lead_id)->exists()
                ? LeadResource::getUrl('edit', ['record' => $followUp->lead_id])
                : null;
        }

        return null;
    }
}
