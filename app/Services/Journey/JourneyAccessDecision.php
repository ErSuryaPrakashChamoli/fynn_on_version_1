<?php

namespace App\Services\Journey;

use App\Enums\JourneyAccessType;

readonly class JourneyAccessDecision
{
    public function __construct(
        public bool $allowed,
        public JourneyAccessType $accessType,
        public ?int $originalOwnerId = null,
        public ?int $actingEmployeeId = null,
        public ?int $delegationId = null,
        public ?int $takeoverId = null,
        public ?string $denialReason = null,
    ) {}

    public static function denied(?string $reason = null): self
    {
        return new self(allowed: false, accessType: JourneyAccessType::Normal, denialReason: $reason);
    }
}
