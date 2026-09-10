<?php

namespace App\Services\Training;

use App\Models\Training\TrainingCertificate;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\User;
use App\Support\Portal\PortalAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues completion certificates.
 *
 * Certificate numbers are FE-TRN-<year>-<5 digits>, allocated inside the
 * same transaction as the row using the year's current maximum so two
 * trainers approving at once cannot collide (the unique index on
 * certificate_number is the backstop).
 */
class CertificateService
{
    public const NUMBER_PREFIX = 'FE-TRN';

    public function __construct(
        protected TrainingProgressService $progress,
    ) {}

    /**
     * @throws ValidationException when the enrollment is not complete
     */
    public function issue(TrainingEnrollment $enrollment, ?User $issuedBy = null): TrainingCertificate
    {
        return DB::transaction(function () use ($enrollment, $issuedBy): TrainingCertificate {
            if ($existing = $enrollment->certificate()->first()) {
                return $existing;
            }

            $this->progress->recalculate($enrollment->refresh());

            if ($enrollment->refresh()->progress_percentage < 100) {
                throw ValidationException::withMessages([
                    'enrollment' => 'This trainee has not completed every lesson yet.',
                ]);
            }

            $certificate = TrainingCertificate::create([
                'tenant_id' => $enrollment->tenant_id,
                'training_enrollment_id' => $enrollment->getKey(),
                'training_course_id' => $enrollment->training_course_id,
                'trainee_id' => $enrollment->trainee_id,
                'certificate_number' => $this->nextNumber(),
                'final_score' => $this->finalScore($enrollment),
                'issued_on' => now()->toDateString(),
                'issued_by' => $issuedBy?->getKey(),
            ]);

            PortalAudit::certificateIssued($certificate);

            return $certificate;
        });
    }

    /**
     * The average of the trainee's best score on every quiz and
     * assessment in the course, falling back to lesson progress when
     * the course carries no graded content at all.
     */
    public function finalScore(TrainingEnrollment $enrollment): int
    {
        $average = TrainingQuizAttempt::query()
            ->where('training_enrollment_id', $enrollment->getKey())
            ->where('status', 'completed')
            ->avg('percentage');

        return (int) round($average ?? $enrollment->progress_percentage);
    }

    protected function nextNumber(): string
    {
        $year = now()->year;
        $prefix = self::NUMBER_PREFIX."-{$year}-";

        $last = TrainingCertificate::query()
            ->where('certificate_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('certificate_number')
            ->value('certificate_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
