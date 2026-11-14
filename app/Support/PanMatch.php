<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Lead;

/**
 * An existing customer file or open lead that already carries a PAN —
 * what App\Services\PanDuplicateService reports when a PAN is typed.
 */
final readonly class PanMatch
{
    public const string CUSTOMER = 'customer';

    public const string LEAD = 'lead';

    public function __construct(
        public string $type,
        public int $id,
        public string $pan,
        public ?string $name,
        public ?string $mobile,
        public ?string $ownerName,
        public ?string $status,
    ) {}

    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            type: self::CUSTOMER,
            id: $customer->id,
            pan: strtoupper((string) $customer->pan_number),
            name: $customer->customer_name,
            mobile: $customer->mobile_no,
            ownerName: $customer->employee?->emp_name,
            status: filled($customer->journey_status) ? str($customer->journey_status)->replace('_', ' ')->title()->toString() : null,
        );
    }

    public static function fromLead(Lead $lead): self
    {
        return new self(
            type: self::LEAD,
            id: $lead->id,
            pan: strtoupper((string) $lead->pan_number),
            name: $lead->customer_name,
            mobile: $lead->mobile_no,
            ownerName: $lead->employee?->emp_name,
            status: $lead->status,
        );
    }

    public function isCustomer(): bool
    {
        return $this->type === self::CUSTOMER;
    }

    public function isLead(): bool
    {
        return $this->type === self::LEAD;
    }

    public function typeLabel(): string
    {
        return $this->isCustomer() ? 'Customer' : 'Lead';
    }

    /**
     * e.g. "customer Rahul Sharma (owner: Amit Kumar)".
     */
    public function description(): string
    {
        $owner = filled($this->ownerName) ? " (owner: {$this->ownerName})" : '';

        return strtolower($this->typeLabel()).' '.($this->name ?? '#'.$this->id).$owner;
    }
}
