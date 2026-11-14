<?php

namespace App\Rules;

use App\Services\PanDuplicateService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails when the PAN already belongs to another customer file or open lead
 * (see PanDuplicateService). Callers decide when an approved request lets
 * a clash through — they leave this rule off in that case.
 */
class PanIsAvailable implements ValidationRule
{
    public function __construct(
        private ?int $ignoreCustomerId = null,
        private ?int $ignoreLeadId = null,
        private bool $mentionApproval = true,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || blank($value)) {
            return;
        }

        $match = app(PanDuplicateService::class)->firstMatch($value, $this->ignoreCustomerId, $this->ignoreLeadId);

        if (! $match) {
            return;
        }

        $fail("This PAN is already used by {$match->description()}."
            .($this->mentionApproval ? ' Request Admin approval to use it.' : ''));
    }
}
