<?php

namespace Database\Seeders\Training;

use App\Enums\PortalRole;
use App\Models\Tenant;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonProgress;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingRemark;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\CertificateService;
use App\Services\Training\TrainingProgressService;
use Database\Seeders\Portal\PortalUserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns the seeded curriculum into a live-looking batch: enrolled
 * trainees at varying progress, quiz attempts, attendance, sessions,
 * trainer remarks and one issued certificate.
 *
 * Progress is written through TrainingProgressService rather than set
 * directly, so the seeded percentages are exactly what the application
 * itself would have computed — a seeded dashboard and a real one cannot
 * disagree.
 */
class TrainingDeliverySeeder extends Seeder
{
    /** @var list<string> */
    public const SESSION_TITLES = [
        'LMS Introduction',
        'Loan Product Training',
        'Lead Calling Process',
        'Documentation Process',
    ];

    public function __construct(
        protected TrainingProgressService $progress,
        protected CertificateService $certificates,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::production();

        $course = TrainingCourse::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('slug', TrainingContentSeeder::COURSE_SLUG)
            ->first();

        if ($course === null) {
            $this->command?->warn('No onboarding course found — run TrainingContentSeeder first.');

            return;
        }

        $trainer = User::query()->where('email', PortalUserSeeder::TRAINER_EMAIL)->first();

        if ($trainer === null) {
            $this->command?->warn('No trainer found — run PortalUserSeeder first.');

            return;
        }

        $batch = $this->seedBatch($tenant, $trainer);
        $trainees = $this->traineesFor($tenant);

        $batch->trainees()->syncWithPivotValues(
            $trainees->pluck('id')->all(),
            ['joined_at' => now()->subDays(20)],
        );

        $this->seedSessions($batch);
        $this->seedEnrollments($batch, $course, $trainees, $trainer);
    }

    protected function seedBatch(Tenant $tenant, User $trainer): TrainingBatch
    {
        return TrainingBatch::updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'code' => 'BATCH-24',
            ],
            [
                'name' => 'New Joiner Batch #24',
                'description' => 'Onboarding cohort for the current intake.',
                'trainer_id' => $trainer->getKey(),
                'starts_on' => now()->subDays(20)->toDateString(),
                'ends_on' => now()->addDays(10)->toDateString(),
                'status' => 'running',
            ]
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    protected function traineesFor(Tenant $tenant)
    {
        return User::query()
            ->whereHas('portalAccount', fn ($query) => $query
                ->where('tenant_id', $tenant->getKey())
                ->where('portal_role', PortalRole::Trainee->value))
            ->orderBy('id')
            ->get();
    }

    protected function seedSessions(TrainingBatch $batch): void
    {
        foreach (self::SESSION_TITLES as $index => $title) {
            TrainingSession::updateOrCreate(
                [
                    'training_batch_id' => $batch->getKey(),
                    'title' => $title,
                ],
                [
                    'agenda' => "Walkthrough and practice for: {$title}.",
                    'scheduled_at' => Carbon::tomorrow()->setTime(10, 0)->addDays($index * 2),
                    'duration_minutes' => 90,
                    'mode' => $index % 2 === 0 ? 'classroom' : 'online',
                    'location' => $index % 2 === 0 ? 'Training Room 1' : 'Online',
                    'status' => 'scheduled',
                ]
            );
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $trainees
     */
    protected function seedEnrollments(
        TrainingBatch $batch,
        TrainingCourse $course,
        $trainees,
        User $trainer,
    ): void {
        $lessons = $course->modules()->with('lessons')->get()->flatMap->lessons->values();
        $totalLessons = $lessons->count();

        if ($totalLessons === 0) {
            return;
        }

        /*
         * A spread of realistic completion levels rather than one
         * number, so the trainer dashboard's "weakest first" ordering
         * and its progress colour bands both have something to show.
         * The first trainee is taken all the way to 100% so there is
         * always one issued certificate in a fresh install.
         */
        $targets = [100, 82, 61, 94, 42, 75, 55, 88, 30, 68, 90, 47, 12];

        foreach ($trainees as $index => $trainee) {
            $target = $targets[$index % count($targets)];

            $enrollment = TrainingEnrollment::updateOrCreate(
                [
                    'training_course_id' => $course->getKey(),
                    'trainee_id' => $trainee->getKey(),
                ],
                [
                    'tenant_id' => $batch->tenant_id,
                    'training_batch_id' => $batch->getKey(),
                    'status' => 'assigned',
                    'enrolled_at' => now()->subDays(20),
                ]
            );

            $this->completeLessonsTo($enrollment, $lessons, $totalLessons, $target);
            $this->seedQuizAttempts($enrollment, $course, $trainee, $target);
            $this->seedAttendance($batch, $trainee, $trainer, $target);
            $this->seedRemark($enrollment, $trainer, $target);

            if ($enrollment->refresh()->progress_percentage >= 100) {
                try {
                    $this->certificates->issue($enrollment, $trainer);
                } catch (ValidationException) {
                    // Nothing to do: the enrollment is not complete after
                    // all, which is a legitimate outcome here.
                }
            }
        }
    }

    /**
     * @param  Collection<int, TrainingLesson>  $lessons
     */
    protected function completeLessonsTo(
        TrainingEnrollment $enrollment,
        $lessons,
        int $totalLessons,
        int $targetPercentage,
    ): void {
        $toComplete = (int) round(($targetPercentage / 100) * $totalLessons);

        foreach ($lessons->take($toComplete) as $lesson) {
            $this->progress->completeLesson($enrollment, $lesson);
        }

        // The lesson after the last completed one is left "in progress",
        // which is what the trainee timeline renders as the current step.
        $current = $lessons->get($toComplete);

        if ($current !== null) {
            TrainingLessonProgress::updateOrCreate(
                [
                    'training_enrollment_id' => $enrollment->getKey(),
                    'training_lesson_id' => $current->getKey(),
                ],
                [
                    'status' => 'in_progress',
                    'progress_percentage' => 40,
                    'started_at' => now()->subDays(2),
                ]
            );
        }

        $this->progress->recalculate($enrollment);
    }

    protected function seedQuizAttempts(
        TrainingEnrollment $enrollment,
        TrainingCourse $course,
        User $trainee,
        int $targetPercentage,
    ): void {
        $quizzes = TrainingQuiz::query()
            ->where('kind', TrainingQuiz::KIND_QUIZ)
            ->whereIn('quizzable_id', $course->lessons()->select('training_lessons.id'))
            ->where('quizzable_type', TrainingLesson::class)
            ->withCount('questions')
            ->take(max(1, (int) round($targetPercentage / 20)))
            ->get();

        foreach ($quizzes as $index => $quiz) {
            $totalMarks = max(1, $quiz->questions_count);
            // Scores track the trainee's overall progress so the
            // dashboard's "weak trainee" story is internally consistent.
            $percentage = min(100, max(20, $targetPercentage + (($index % 3) - 1) * 8));
            $score = (int) round(($percentage / 100) * $totalMarks);

            TrainingQuizAttempt::updateOrCreate(
                [
                    'training_quiz_id' => $quiz->getKey(),
                    'training_enrollment_id' => $enrollment->getKey(),
                    'attempt_number' => 1,
                ],
                [
                    'trainee_id' => $trainee->getKey(),
                    'score' => $score,
                    'total_marks' => $totalMarks,
                    'percentage' => $percentage,
                    'passed' => $percentage >= $quiz->pass_percentage,
                    'status' => 'completed',
                    'started_at' => now()->subDays(5),
                    'completed_at' => now()->subDays(5)->addMinutes(9),
                ]
            );
        }
    }

    protected function seedAttendance(
        TrainingBatch $batch,
        User $trainee,
        User $trainer,
        int $targetPercentage,
    ): void {
        for ($day = 1; $day <= 10; $day++) {
            $date = now()->subDays($day);

            if ($date->isWeekend()) {
                continue;
            }

            // Weaker trainees miss more days — the attendance report and
            // the progress report should tell the same story.
            $status = match (true) {
                $day % 7 === 0 && $targetPercentage < 60 => 'absent',
                $day % 5 === 0 && $targetPercentage < 80 => 'late',
                default => 'present',
            };

            TrainingAttendance::updateOrCreate(
                [
                    'training_batch_id' => $batch->getKey(),
                    'trainee_id' => $trainee->getKey(),
                    'attendance_date' => $date->toDateString(),
                ],
                [
                    'status' => $status,
                    'marked_by' => $trainer->getKey(),
                ]
            );
        }
    }

    protected function seedRemark(TrainingEnrollment $enrollment, User $trainer, int $targetPercentage): void
    {
        $remark = match (true) {
            $targetPercentage >= 85 => 'Strong product knowledge and confident on calls. Ready for the floor.',
            $targetPercentage >= 60 => 'Good product knowledge. Needs improvement in objection handling and bank eligibility.',
            default => 'Falling behind on the module schedule. Needs a catch-up session before the assessment.',
        };

        TrainingRemark::updateOrCreate(
            [
                'training_enrollment_id' => $enrollment->getKey(),
                'trainer_id' => $trainer->getKey(),
                'remark' => $remark,
            ],
            [
                'rating' => (int) max(1, min(5, round($targetPercentage / 20))),
                'is_visible_to_trainee' => true,
            ]
        );
    }
}
