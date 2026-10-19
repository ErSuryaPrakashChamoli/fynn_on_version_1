<?php

namespace App\Enums;

/**
 * Why a bureau request did not produce a usable answer, for the Failed
 * Requests screen and the dashboard. Set from the client's exception (or
 * by the service for a crash or a stale pending request); local validation
 * and consent problems never get this far — nothing is recorded or sent.
 */
enum CibilFailureCategory: string
{
    case NotConfigured = 'not_configured';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case RateLimit = 'rate_limit';
    case ProviderRejected = 'provider_rejected';
    case ProviderError = 'provider_error';
    case Network = 'network';
    case Timeout = 'timeout';
    case MalformedResponse = 'malformed_response';
    case MissingData = 'missing_data';
    case Internal = 'internal';
    case UnknownOutcome = 'unknown_outcome';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Not configured',
            self::Authentication => 'Authentication failed',
            self::Authorization => 'Not permitted',
            self::RateLimit => 'Rate limited',
            self::ProviderRejected => 'Rejected by provider',
            self::ProviderError => 'Provider server error',
            self::Network => 'Network error',
            self::Timeout => 'Timeout',
            self::MalformedResponse => 'Malformed response',
            self::MissingData => 'Missing expected data',
            self::Internal => 'Internal error',
            self::UnknownOutcome => 'Unknown outcome',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Timeout, self::UnknownOutcome, self::RateLimit => 'warning',
            self::NotConfigured, self::Authentication, self::Authorization => 'gray',
            default => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])
            ->all();
    }
}
