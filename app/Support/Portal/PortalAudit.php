<?php

namespace App\Support\Portal;

use App\Models\PortalAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for the portals, written into the LMS's existing
 * spatie/laravel-activitylog table under its own log name.
 *
 * A dedicated log name ('portal') is what keeps these entries out of the
 * admin panel's ActivityLog resource by default while still using one
 * table and one schema — no parallel audit system, no migration.
 *
 * Deliberately records identifiers and outcomes only. Never a password,
 * never a token, never document contents.
 */
class PortalAudit
{
    public const LOG_NAME = 'portal';

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $description, ?Model $subject = null, array $properties = []): void
    {
        $activity = activity(self::LOG_NAME)
            ->withProperties($properties + self::context());

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        if ($causer = auth()->user()) {
            $activity->causedBy($causer);
        }

        $activity->log($description);
    }

    public static function courseCreated(Model $course): void
    {
        self::record('Training course created', $course, [
            'title' => $course->getAttribute('title'),
        ]);
    }

    public static function traineeAssigned(Model $enrollment): void
    {
        self::record('Trainee assigned to course', $enrollment, [
            'trainee_id' => $enrollment->getAttribute('trainee_id'),
            'course_id' => $enrollment->getAttribute('training_course_id'),
        ]);
    }

    public static function certificateIssued(Model $certificate): void
    {
        self::record('Certificate issued', $certificate, [
            'certificate_number' => $certificate->getAttribute('certificate_number'),
            'trainee_id' => $certificate->getAttribute('trainee_id'),
            'final_score' => $certificate->getAttribute('final_score'),
        ]);
    }

    public static function assessmentGraded(Model $attempt): void
    {
        self::record('Assessment graded', $attempt, [
            'trainee_id' => $attempt->getAttribute('trainee_id'),
            'percentage' => $attempt->getAttribute('percentage'),
            'passed' => $attempt->getAttribute('passed'),
        ]);
    }

    public static function portalAccountCreated(PortalAccount $account): void
    {
        self::record('Portal account created', $account, [
            'portal' => $account->portal->value,
            'portal_role' => $account->portal_role->value,
            'tenant_id' => $account->tenant_id,
            'expires_at' => $account->expires_at?->toIso8601String(),
        ]);
    }

    public static function portalAccountRevoked(PortalAccount $account): void
    {
        self::record('Portal account revoked', $account, [
            'portal' => $account->portal->value,
            'user_id' => $account->user_id,
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function demoReset(Tenant $tenant, array $counts): void
    {
        self::record('Demo environment reset', $tenant, [
            'tenant' => $tenant->slug,
            'row_counts' => $counts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function context(): array
    {
        $account = app(PortalContext::class)->account();

        return array_filter([
            'portal' => $account?->portal->value,
            'tenant_id' => $account?->tenant_id,
            'ip' => request()->ip(),
        ], fn ($value): bool => $value !== null);
    }
}
